import {
    cellString,
    parseDay,
    parseSpanishNumber,
    positionsFrom,
    tradeChange,
} from '@/lib/broker-imports/grid';
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

/** What an order type does to the holding. */
type OrderEffect =
    | { side: Trade['side']; isTransfer: boolean }
    | 'dividend'
    | 'ignored';

/**
 * Each order type, by normalised text. Traspasos and switches move money
 * from one fund to another inside the account: both legs are in the file and
 * net out of what was invested.
 */
function effectOf(type: string): OrderEffect | null {
    const name = normalizeText(type);
    const isSwitch = name.includes('switch');
    const rules: [boolean, OrderEffect][] = [
        [
            name === 'compra' || name === 'suscripcion',
            { side: 'buy', isTransfer: false },
        ],
        [
            name === 'suscr por traspaso' ||
                (name.startsWith('alta') && isSwitch),
            { side: 'buy', isTransfer: true },
        ],
        [name.startsWith('aportacion'), { side: 'buy', isTransfer: false }],
        [
            name === 'venta' || name === 'reembolso',
            { side: 'sell', isTransfer: false },
        ],
        [
            name === 'reemb por traspaso' ||
                (name.startsWith('baja') && isSwitch),
            { side: 'sell', isTransfer: true },
        ],
        [name.startsWith('prestacion'), { side: 'sell', isTransfer: false }],
        [name.startsWith('dividendo'), 'dividend'],
        [name.startsWith('canje'), 'ignored'],
    ];

    return rules.find(([matches]) => matches)?.[1] ?? null;
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
    /** From trades and unassigned orders alike. */
    positions: Position[];
}

/**
 * The column of a row's ISIN, or, for a pension plan, of where it would be:
 * right after an order type the reader knows, with a date where the dates go.
 */
export function anchorOf(row: unknown[]): number {
    const isin = row.findIndex((cell) => ISIN.test(cellString(cell)));

    if (isin !== -1) {
        return isin;
    }

    const type = row.findIndex(
        (cell, index) =>
            index + OFFSET.date + 1 >= 0 &&
            effectOf(cellString(cell)) !== null &&
            parseDay(row[index + OFFSET.date + 1]) !== null,
    );

    return type === -1 ? -1 : type + 1;
}

/** Whether a row is an order of the securities export: an ISIN cell right after a known order type. */
export function isSecuritiesOrderRow(row: unknown[]): boolean {
    const isin = row.findIndex((cell) => ISIN.test(cellString(cell)));

    return isin > 0 && effectOf(cellString(row[isin - 1])) !== null;
}

/**
 * Reads the Inversis securities export MyInvestor hands out as `salida.xls`
 * (an HTML table or a real xlsx). Every row is read from its ISIN cell, so the
 * misaligned header is never needed.
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

    for (const row of grid) {
        const anchor = anchorOf(row);
        const at = (offset: number) => row[anchor + offset];
        const date = anchor === -1 ? null : parseDay(at(OFFSET.date));
        const effect = date ? effectOf(cellString(at(OFFSET.type))) : null;

        if (!date || effect === null || effect === 'ignored') {
            continue;
        }

        const symbol = cellString(at(0)) || cellString(at(OFFSET.name));
        const currency =
            parseCurrencyCode(cellString(at(OFFSET.currency))) ?? 'EUR';
        const amount = Math.abs(
            toMinorUnits(parseSpanishNumber(at(OFFSET.amount)) ?? 0, currency),
        );
        const name = cellString(at(OFFSET.name)) || symbol;
        const externalId =
            cellString(at(OFFSET.order)) || `${date}-${symbol}-${amount}`;

        if (effect === 'dividend') {
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
        const market = normalizeText(at(OFFSET.market));

        (hasIsin ? result.trades : result.unassigned).push({
            date,
            side: effect.side,
            symbol,
            name,
            assetClass: hasIsin
                ? market.includes('fondos')
                    ? 'FUND'
                    : 'STOCK'
                : 'PENSION',
            shares: Math.abs(parseSpanishNumber(at(OFFSET.shares)) ?? 0),
            amount,
            currency,
            externalId,
            isTransfer: effect.isTransfer,
        });
    }

    result.positions = positionsFrom(
        [...result.trades, ...result.unassigned].map(tradeChange),
    );

    return result;
}
