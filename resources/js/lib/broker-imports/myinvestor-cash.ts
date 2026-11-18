import {
    cellString,
    columnIndexes,
    findHeaderRow,
    parseDay,
    parseSpanishNumber,
    stableHash,
} from '@/lib/broker-imports/grid';
import {
    type BrokerGrid,
    type CashMovement,
    type CashMovementKind,
} from '@/lib/broker-imports/types';
import { parseCurrencyCode } from '@/lib/file-parser';
import { normalizeText } from '@/lib/full-import-profiles';
import { toMinorUnits } from '@/utils/currency';

/** What each column of the current-account export can be called, by normalised name. */
const COLUMN_NAMES = {
    date: ['fecha', 'fecha operacion', 'fecha de operacion'],
    valueDate: ['fecha valor', 'fecha de valor'],
    concept: ['concepto', 'movimiento', 'descripcion'],
    amount: ['importe'],
    balance: ['saldo'],
    currency: ['divisa'],
} as const;

type Column = keyof typeof COLUMN_NAMES;

const KNOWN_NAMES: string[] = Object.values(COLUMN_NAMES).flat();

/**
 * Whether a row's normalised cells are the current account's header: a date,
 * a concept and an amount, and nothing the export does not write. The last
 * rule keeps other Spanish exports (Banktrack's has a "Banco" and a
 * "Categorías" column) from being taken for MyInvestor's.
 */
export function isMyInvestorCashHeader(names: string[]): boolean {
    const present = names.filter(Boolean);
    const has = (column: Column) =>
        present.some((name) =>
            (COLUMN_NAMES[column] as readonly string[]).includes(name),
        );

    return (
        has('date') &&
        has('concept') &&
        has('amount') &&
        present.every((name) => KNOWN_NAMES.includes(name))
    );
}

/** The first words of a concept, by normalised text, and the kind they mean. */
const KIND_BY_CONCEPT: [string, CashMovementKind][] = [
    ['compra con tarjeta', 'card'],
    ['devolucion compra', 'card'],
    ['periodo', 'interest'],
    ['intereses', 'interest'],
    ['retencion', 'tax'],
    ['comision', 'fee'],
    ['transferencia recibida', 'transfer_in'],
    ['bizum recibido', 'transfer_in'],
    ['transferencia emitida', 'transfer_out'],
    ['bizum enviado', 'transfer_out'],
    ['traspaso a cuenta de valores', 'transfer_out'],
    ['traspaso desde cuenta de valores', 'transfer_in'],
    ['recibo', 'direct_debit'],
    ['adeudo', 'direct_debit'],
    ['promocion', 'bonus'],
];

function kindOf(concept: string): CashMovementKind {
    const normalized = normalizeText(concept);
    const match = KIND_BY_CONCEPT.find(([start]) =>
        normalized.startsWith(start),
    );

    if (match) {
        return match[1];
    }

    // Fund orders settled from the account read "FUND NAME @ SUSCRIPCION".
    if (concept.includes('@')) {
        return normalized.endsWith('reembolso') ? 'sell' : 'buy';
    }

    return 'other';
}

function columnsOf(header: unknown[]): Partial<Record<Column, number>> {
    const indexes = columnIndexes(header);
    const columns: Partial<Record<Column, number>> = {};

    for (const [column, names] of Object.entries(COLUMN_NAMES)) {
        const index = names
            .map((name) => indexes.get(name))
            .find((one) => one !== undefined);

        if (index !== undefined) {
            columns[column as Column] = index;
        }
    }

    return columns;
}

export interface MyInvestorCashFile {
    movements: CashMovement[];
    /** Whether each movement carries the balance after it. */
    hasBalance: boolean;
}

/**
 * Reads MyInvestor's current-account "Resumen de movimientos", as xlsx (title
 * rows above the header) or as the `;` CSV. The bank gives no id per
 * movement, so each gets a hash of its day, amount and concept, plus its
 * ordinal among identical rows of the file: two equal coffees on one day stay
 * two transactions, and the same file imported twice gets the same ids.
 */
export function parseMyInvestorCash(grid: BrokerGrid): MyInvestorCashFile {
    const headerRow = findHeaderRow(grid, isMyInvestorCashHeader);

    if (headerRow === -1) {
        return { movements: [], hasBalance: false };
    }

    const columns = columnsOf(grid[headerRow]);
    const hasBalance = columns.balance !== undefined;
    const occurrences = new Map<string, number>();
    const movements: CashMovement[] = [];

    for (const row of grid.slice(headerRow + 1)) {
        const cell = (column: Column) =>
            columns[column] === undefined ? undefined : row[columns[column]];
        const date = parseDay(cell('date'));
        const value = parseSpanishNumber(cell('amount'));

        if (!date || value === null) {
            continue;
        }

        const currency =
            parseCurrencyCode(cellString(cell('currency'))) ?? 'EUR';
        const amount = toMinorUnits(value, currency);
        const description = cellString(cell('concept'));
        const key = stableHash(
            `${date}|${amount}|${normalizeText(description)}`,
        );
        const ordinal = occurrences.get(key) ?? 0;
        const balance = hasBalance ? parseSpanishNumber(cell('balance')) : null;

        occurrences.set(key, ordinal + 1);
        movements.push({
            date,
            amount,
            currency,
            description,
            externalId: `myinvestor-${key}-${ordinal}`,
            kind: kindOf(description),
            ...(balance === null
                ? {}
                : { balance: toMinorUnits(balance, currency) }),
        });
    }

    return { movements, hasBalance };
}
