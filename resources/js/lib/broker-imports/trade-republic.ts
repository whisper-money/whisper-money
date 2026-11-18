import {
    cellString,
    columnIndexes,
    findHeaderRow,
    positionsFrom,
    tradeChange,
    type PositionChange,
} from '@/lib/broker-imports/grid';
import {
    type BrokerGrid,
    type CashMovement,
    type CashMovementKind,
    type Position,
    type Trade,
} from '@/lib/broker-imports/types';
import { parseAmount, parseDate } from '@/lib/file-parser';
import { normalizeText } from '@/lib/full-import-profiles';
import { DateFormat } from '@/types/import';
import { toMinorUnits } from '@/utils/currency';
import { formatLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

/** The columns every Trade Republic transaction export carries, by normalised name. */
const SHARED_HEADER = [
    'datetime',
    'date',
    'account type',
    'category',
    'type',
    'asset class',
    'name',
    'symbol',
    'shares',
    'price',
    'amount',
    'fee',
    'tax',
    'currency',
    'original amount',
    'original currency',
    'fx rate',
    'description',
    'transaction id',
    'counterparty name',
    'counterparty iban',
    'payment reference',
];

/** The 2026 layout: comma separated, with the card's merchant category code. */
const CURRENT_HEADER = [...SHARED_HEADER, 'mcc code'];

/** The older layout: semicolon separated, a value date and no merchant category code. */
const LEGACY_HEADER = [...SHARED_HEADER, 'value date'];

/** Whether a row's normalised cells are a Trade Republic export's header. */
export function isTradeRepublicHeader(names: string[]): boolean {
    const carries = (header: string[]) =>
        header.every((name) => names.includes(name));

    return carries(CURRENT_HEADER) || carries(LEGACY_HEADER);
}

/** Each Trade Republic row type, by its code, as the kind of cash movement it is. */
const KIND_BY_TYPE: Record<string, CashMovementKind> = {
    CARD_TRANSACTION: 'card',
    CARD_TRANSACTION_INTERNATIONAL: 'card',
    CARD_TRANSACTION_REFUND: 'card',
    CARD_TRANSACTION_REFUND_REVERSAL: 'card',
    CUSTOMER_INPAYMENT: 'transfer_in',
    CUSTOMER_INBOUND: 'transfer_in',
    TRANSFER_INBOUND: 'transfer_in',
    TRANSFER_INSTANT_INBOUND: 'transfer_in',
    TRANSFER_IN: 'transfer_in',
    GENERAL_INBOUND: 'transfer_in',
    VIBAN_TRANSFER_INBOUND: 'transfer_in',
    TRANSFER_OUTBOUND: 'transfer_out',
    TRANSFER_INSTANT_OUTBOUND: 'transfer_out',
    TRANSFER_OUT: 'transfer_out',
    CUSTOMER_OUTBOUND_REQUEST: 'transfer_out',
    VIBAN_TRANSFER_OUTBOUND: 'transfer_out',
    DIRECT_DEBIT: 'direct_debit',
    TRANSFER_DIRECT_DEBIT_INBOUND: 'direct_debit',
    INTEREST_PAYMENT: 'interest',
    DIVIDEND: 'dividend',
    DISTRIBUTION: 'dividend',
    DIVIDEND_EQUIVALENT_PAYMENT: 'dividend',
    BENEFITS_SAVEBACK: 'saveback',
    BONUS: 'bonus',
    REFERRAL: 'bonus',
    GIFT: 'bonus',
    KINDERGELD_BONUS: 'bonus',
    STOCKPERK: 'bonus',
    BUY: 'buy',
    PRIVATE_MARKET_BUY: 'buy',
    SELL: 'sell',
    PRIVATE_MARKET_SELL: 'sell',
    CARD_ORDERING_FEE: 'fee',
    FEE: 'fee',
    TAX_OPTIMIZATION: 'tax',
    EARNINGS: 'tax',
    PRE_DETERMINED_TAX_BASE: 'tax',
    TAX: 'tax',
};

/** Descriptions Trade Republic writes when it has nothing to say. */
const PLACEHOLDER_DESCRIPTIONS = ['no sepadescription provided'];

export interface TradeRepublicFile {
    cash: CashMovement[];
    trades: Trade[];
    positions: Position[];
}

/** One data row, read by column name. */
type RowCells = (name: string) => string;

/** The signed minor units of a numeric column, 0 when it is empty. */
function minorUnits(text: string, currency: string): number {
    const value = parseAmount(text);

    return value === null ? 0 : toMinorUnits(value, currency);
}

function bookingDay(cells: RowCells): string | null {
    const date = parseDate(
        cells('date') || cells('datetime').slice(0, 10),
        DateFormat.YearMonthDay,
    );

    return date ? formatLocalDate(date) : null;
}

/**
 * The kind of a row. A type the table does not know is still a fee or a tax
 * when that is all it moves, and otherwise `other`.
 */
function kindOf(
    type: string,
    amount: number,
    fee: number,
    tax: number,
): CashMovementKind {
    const known = KIND_BY_TYPE[type];

    if (known) {
        return known;
    }

    if (amount === 0 && tax === 0 && fee !== 0) {
        return 'fee';
    }

    return amount === 0 && fee === 0 && tax !== 0 ? 'tax' : 'other';
}

/** A card row's merchant: international rows append the foreign amount after a comma. */
function merchantOf(cells: RowCells): string {
    if (cells('name')) {
        return cells('name');
    }

    return cells('type') === 'CARD_TRANSACTION_INTERNATIONAL'
        ? cells('description').split(',')[0].trim()
        : cells('description');
}

function usefulDescription(cells: RowCells): string {
    const description = cells('description');

    return PLACEHOLDER_DESCRIPTIONS.includes(normalizeText(description))
        ? ''
        : description;
}

/** What the movement is called in the transaction list. */
function describe(kind: CashMovementKind, cells: RowCells): string {
    const instrument = cells('name') || cells('symbol');

    switch (kind) {
        case 'card':
            return merchantOf(cells);
        case 'buy':
            return __('Buy · :name', { name: instrument });
        case 'sell':
            return __('Sell · :name', { name: instrument });
        case 'dividend':
            return __('Dividend · :name', { name: instrument });
        case 'interest':
            return __('Interest');
        case 'saveback':
            return __('Saveback');
        default:
            return (
                cells('counterparty name') ||
                usefulDescription(cells) ||
                cells('type')
            );
    }
}

function tradeOf(
    side: Trade['side'],
    date: string,
    net: number,
    cells: RowCells,
): Trade {
    const shares = parseAmount(cells('shares')) ?? 0;

    return {
        date,
        side,
        symbol: cells('symbol') || cells('name'),
        name: cells('name') || cells('symbol'),
        assetClass: cells('asset class') || null,
        shares: Math.abs(shares),
        amount: Math.abs(net),
        currency: cells('currency') || 'EUR',
        externalId: cells('transaction id'),
        isTransfer: false,
    };
}

/** Shares that arrived without cash (crypto rewards, transfers in from another broker). */
function deliveryOf(cells: RowCells): PositionChange | null {
    if (cells('category') !== 'DELIVERY' || !cells('symbol')) {
        return null;
    }

    return {
        symbol: cells('symbol'),
        name: cells('name') || cells('symbol'),
        assetClass: cells('asset class') || null,
        currency: cells('currency') || 'EUR',
        shares: parseAmount(cells('shares')) ?? 0,
        invested: 0,
    };
}

function cashMovementOf(date: string, cells: RowCells): CashMovement | null {
    const currency = cells('currency') || 'EUR';
    const amount = minorUnits(cells('amount'), currency);
    const fee = minorUnits(cells('fee'), currency);
    const tax = minorUnits(cells('tax'), currency);
    const net = amount + fee + tax;

    if (net === 0) {
        return null;
    }

    const kind = kindOf(cells('type'), amount, fee, tax);

    return {
        date,
        amount: net,
        currency,
        description: describe(kind, cells),
        externalId: cells('transaction id'),
        kind,
        ...(cells('symbol') ? { isin: cells('symbol') } : {}),
        ...(cells('name') ? { name: cells('name') } : {}),
    };
}

/**
 * Reads a Trade Republic transaction export. Every row that moves cash is a
 * cash movement, trades included, since the export has no separate settlement
 * row: the buy itself is what left the cash account. Rows repeated by
 * overlapping exports are read once, by their transaction id.
 */
export function parseTradeRepublic(grid: BrokerGrid): TradeRepublicFile {
    const headerRow = findHeaderRow(grid, isTradeRepublicHeader);
    const result: TradeRepublicFile = { cash: [], trades: [], positions: [] };

    if (headerRow === -1) {
        return result;
    }

    const indexes = columnIndexes(grid[headerRow]);
    const seen = new Set<string>();
    const changes: PositionChange[] = [];

    for (const row of grid.slice(headerRow + 1)) {
        const cells: RowCells = (name) =>
            cellString(row[indexes.get(name) ?? -1]);
        const id = cells('transaction id');
        const date = bookingDay(cells);

        if (!date || !id || seen.has(id)) {
            continue;
        }

        seen.add(id);

        const movement = cashMovementOf(date, cells);
        const delivery = deliveryOf(cells);

        if (movement) {
            result.cash.push(movement);
        }

        if (movement && (movement.kind === 'buy' || movement.kind === 'sell')) {
            const trade = tradeOf(movement.kind, date, movement.amount, cells);
            result.trades.push(trade);
            changes.push(tradeChange(trade));
        } else if (delivery) {
            changes.push(delivery);
        }
    }

    result.positions = positionsFrom(changes);

    return result;
}
