import {
    cellString,
    DEFAULT_CURRENCY,
    parseDay,
    parseSpanishNumber,
} from '@/lib/broker-imports/grid';
import { rowIdFactory } from '@/lib/broker-imports/ids';
import { positionsCarryingTransfers } from '@/lib/broker-imports/positions';
import {
    type BrokerGrid,
    type Position,
    type Trade,
} from '@/lib/broker-imports/types';
import { parseCurrencyCode } from '@/lib/file-parser';
import { normalizeText } from '@/lib/full-import-profiles';
import { toMinorUnits } from '@/utils/currency';

/**
 * Where each field sits, counted from the row's ISIN cell. The header has a
 * merged "Fechas" cell over the two dates, and the xlsx variant an extra empty
 * first column, so header positions do not line up with the data; the ISIN
 * cell is the one fixed point of every row.
 */
const OFFSET = {
    date: -5,
    order: -3,
    market: -2,
    type: -1,
    name: 1,
    shares: 2,
    currency: 3,
    amount: 5,
} as const;

const ISIN = /^[A-Z]{2}[A-Z0-9]{9}\d$/;

/** Inversis' order number, the column between the dates and the market. */
const ORDER_NUMBER = /^\d{6,}$/;

/** What an order type does to the holding. */
type OrderEffect =
    | { type: 'trade'; side: Trade['side']; isTransfer: boolean }
    | { type: 'dividend' }
    | { type: 'ignored' };

const BUY: OrderEffect = { type: 'trade', side: 'buy', isTransfer: false };
const SELL: OrderEffect = { type: 'trade', side: 'sell', isTransfer: false };
const TRANSFER_IN: OrderEffect = {
    type: 'trade',
    side: 'buy',
    isTransfer: true,
};
const TRANSFER_OUT: OrderEffect = {
    type: 'trade',
    side: 'sell',
    isTransfer: true,
};

/** Order types written in full, by normalised text. */
const EFFECT_BY_TYPE: Record<string, OrderEffect> = {
    compra: BUY,
    suscripcion: BUY,
    'suscr por traspaso': TRANSFER_IN,
    venta: SELL,
    reembolso: SELL,
    'reemb por traspaso': TRANSFER_OUT,
};

/**
 * Order types known by how they start: switches name the fund family in the
 * middle ("ALTA IIC SWITCH"), pension plans the kind of contribution
 * ("APORTACION PERIODICA").
 */
const EFFECT_BY_PREFIX: [
    prefix: string,
    effect: OrderEffect,
    contains?: string,
][] = [
    ['alta', TRANSFER_IN, 'switch'],
    ['baja', TRANSFER_OUT, 'switch'],
    ['aportacion', BUY],
    ['prestacion', SELL],
    ['dividendo', { type: 'dividend' }],
    ['canje', { type: 'ignored' }],
];

/**
 * What an order type does, by normalised text, or null for a cell that is no
 * order type. Traspasos and switches move money from one fund to another
 * inside the account: both legs are in the file and net out of what was
 * invested.
 */
function effectOf(type: unknown): OrderEffect | null {
    const name = normalizeText(type);
    const prefixed = EFFECT_BY_PREFIX.find(
        ([prefix, , contains = '']) =>
            name.startsWith(prefix) && name.includes(contains),
    );

    return EFFECT_BY_TYPE[name] ?? prefixed?.[1] ?? null;
}

function isinColumn(row: unknown[]): number {
    return row.findIndex((cell) => ISIN.test(cellString(cell)));
}

export interface SecuritiesDividend {
    date: string;
    symbol: string;
    name: string;
    /** Positive, in minor units. */
    amount: number;
    currency: string;
    externalId: string;
}

export interface MyInvestorSecuritiesFile {
    /** Orders on a security or fund, by ISIN. */
    trades: Trade[];
    /**
     * Orders with no ISIN: pension plans, which carry a DGS code instead.
     * They have no security to hold but still count toward what was invested.
     */
    unassigned: Trade[];
    dividends: SecuritiesDividend[];
    /** From trades and unassigned orders alike, transfers carrying their cost. */
    positions: Position[];
}

/**
 * The column of a row's ISIN, or, for a pension plan, of where it would be:
 * right after an order type the reader knows, with a date where the dates go.
 */
function anchorOf(row: unknown[]): number {
    const isin = isinColumn(row);

    if (isin !== -1) {
        return isin;
    }

    const type = row.findIndex(
        (cell, index) =>
            index + OFFSET.date - OFFSET.type >= 0 &&
            effectOf(cell) !== null &&
            parseDay(row[index + OFFSET.date - OFFSET.type]) !== null,
    );

    return type === -1 ? -1 : type - OFFSET.type;
}

/**
 * Whether a row is an order of the securities export: an ISIN right after a
 * known order type, Inversis' order number before them and a date where the
 * dates go. Other brokers with "Tipo, ISIN" columns side by side lack the rest.
 */
export function isSecuritiesOrderRow(row: unknown[]): boolean {
    const isin = isinColumn(row);
    const at = (offset: number) => row[isin + offset];

    return (
        isin + OFFSET.date >= 0 &&
        effectOf(at(OFFSET.type)) !== null &&
        ORDER_NUMBER.test(cellString(at(OFFSET.order))) &&
        parseDay(at(OFFSET.date)) !== null
    );
}

function assetClassOf(hasIsin: boolean, market: unknown): string {
    if (!hasIsin) {
        return 'PENSION';
    }

    return normalizeText(market).includes('fondos') ? 'FUND' : 'STOCK';
}

/**
 * Reads the Inversis securities export MyInvestor hands out as `salida.xls`
 * (an HTML table or a real xlsx). Every row is read from its ISIN cell, so the
 * misaligned header is never needed. Ids come from the order number, the
 * order type and the security, with an ordinal for repeats, so the two legs of
 * a switch or a dividend never share one with a trade.
 */
export function parseMyInvestorSecurities(
    grid: BrokerGrid,
): MyInvestorSecuritiesFile {
    const result: MyInvestorSecuritiesFile = {
        trades: [],
        unassigned: [],
        dividends: [],
        positions: [],
    };
    const idOf = rowIdFactory('myinvestor-securities');

    for (const row of grid) {
        const anchor = anchorOf(row);
        const at = (offset: number) => row[anchor + offset];
        const date = anchor === -1 ? null : parseDay(at(OFFSET.date));
        const effect = date ? effectOf(at(OFFSET.type)) : null;
        const value = parseSpanishNumber(at(OFFSET.amount));

        if (!date || !effect || effect.type === 'ignored' || value === null) {
            continue;
        }

        const symbol = cellString(at(0)) || cellString(at(OFFSET.name));
        const currency =
            parseCurrencyCode(cellString(at(OFFSET.currency))) ??
            DEFAULT_CURRENCY;
        const amount = Math.abs(toMinorUnits(value, currency));
        const name = cellString(at(OFFSET.name)) || symbol;
        const externalId = idOf([
            date,
            cellString(at(OFFSET.order)),
            normalizeText(at(OFFSET.type)),
            symbol,
            amount,
        ]);

        if (effect.type === 'dividend') {
            result.dividends.push({
                date,
                symbol,
                name,
                amount,
                currency,
                externalId,
            });
            continue;
        }

        const hasIsin = ISIN.test(cellString(at(0)));

        (hasIsin ? result.trades : result.unassigned).push({
            date,
            side: effect.side,
            symbol,
            name,
            assetClass: assetClassOf(hasIsin, at(OFFSET.market)),
            shares: Math.abs(parseSpanishNumber(at(OFFSET.shares)) ?? 0),
            amount,
            currency,
            externalId,
            isTransfer: effect.isTransfer,
        });
    }

    result.positions = positionsCarryingTransfers([
        ...result.trades,
        ...result.unassigned,
    ]);

    return result;
}
