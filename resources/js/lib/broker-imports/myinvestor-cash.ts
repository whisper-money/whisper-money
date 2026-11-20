import {
    cellString,
    columnIndexes,
    DEFAULT_CURRENCY,
    findHeaderRow,
    parseDay,
    parseSpanishNumber,
} from '@/lib/broker-imports/grid';
import { rowIdFactory } from '@/lib/broker-imports/ids';
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

/** The header of the `;` CSV, exactly as MyInvestor writes it. */
const CSV_HEADER = [
    'fecha de operacion',
    'fecha de valor',
    'concepto',
    'importe',
    'divisa',
];

/**
 * What the title rows of the xlsx say that no other bank's export does: its
 * title, or an account at MyInvestor's bank code (1544).
 */
const TITLE_FINGERPRINTS = [
    /resumen de movimientos/,
    /myinvestor/,
    /^es\d{2} ?1544/,
];

/**
 * The account template xlsx (a sheet whose only MyInvestor mark is its logo
 * image) labels the holder and the account number, each in its own cell with
 * its value in the next one. The number is the bare 10 digits, not the IBAN
 * other banks write there.
 */
const ACCOUNT_TEMPLATE_HOLDER_LABEL = 'titular';
const ACCOUNT_TEMPLATE_ACCOUNT_LABEL = 'cuenta';
const ACCOUNT_TEMPLATE_NUMBER = /^\d{10}$/;

/** The columns the account template's header always has, by normalised name. */
const ACCOUNT_TEMPLATE_HEADER = [
    'fecha operacion',
    'fecha valor',
    'movimiento',
    'importe',
    'saldo',
];

/** Whether a row's normalised cells have a date, a concept and an amount column. */
function looksLikeCashHeader(names: string[]): boolean {
    const has = (column: Column) =>
        names.some((name) =>
            (COLUMN_NAMES[column] as readonly string[]).includes(name),
        );

    return has('date') && has('concept') && has('amount');
}

function hasMyInvestorTitle(rows: unknown[][]): boolean {
    return rows.some((row) =>
        row.some((cell) => {
            const text = normalizeText(cell);

            return TITLE_FINGERPRINTS.some((pattern) => pattern.test(text));
        }),
    );
}

/** Whether some row has the label in a cell and the next cell passes the test. */
function hasLabelledCell(
    rows: unknown[][],
    label: string,
    value: (cell: unknown) => boolean = () => true,
): boolean {
    return rows.some((row) =>
        row.some(
            (cell, index) =>
                normalizeText(cell) === label && value(row[index + 1]),
        ),
    );
}

/**
 * Whether the rows above the header label the holder and the 10-digit
 * account number, and the header has every column of the account template.
 * Either alone is what other Spanish banks write too; together they are this
 * template's.
 */
function isAccountTemplate(rows: unknown[][], names: string[]): boolean {
    return (
        ACCOUNT_TEMPLATE_HEADER.every((name) => names.includes(name)) &&
        hasLabelledCell(rows, ACCOUNT_TEMPLATE_HOLDER_LABEL) &&
        hasLabelledCell(rows, ACCOUNT_TEMPLATE_ACCOUNT_LABEL, (cell) =>
            ACCOUNT_TEMPLATE_NUMBER.test(cellString(cell)),
        )
    );
}

/**
 * The row of the current account's header, or -1. A date, concept and
 * amount header is what most Spanish banks write, so it only counts as
 * MyInvestor's with something MyInvestor's own: the CSV's exact header, the
 * xlsx's title rows above it, or the account template's holder and 10-digit
 * account number above its full header. Any other "Fecha, Concepto, Importe" file
 * stays with the generic importer.
 */
export function findMyInvestorCashHeader(grid: BrokerGrid): number {
    const row = findHeaderRow(grid, looksLikeCashHeader);

    if (row === -1) {
        return -1;
    }

    const names = grid[row].map(normalizeText);
    const isCsvHeader = CSV_HEADER.every((name) => names.includes(name));

    const above = grid.slice(0, row);

    return isCsvHeader ||
        hasMyInvestorTitle(above) ||
        isAccountTemplate(above, names)
        ? row
        : -1;
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
 * rows above the header), as the account template xlsx or as the `;` CSV.
 * The bank gives no id per movement, so each gets one from its day, amount
 * and concept (`rowIdFactory`).
 */
export function parseMyInvestorCash(grid: BrokerGrid): MyInvestorCashFile {
    const headerRow = findMyInvestorCashHeader(grid);

    if (headerRow === -1) {
        return { movements: [], hasBalance: false };
    }

    const columns = columnsOf(grid[headerRow]);
    const hasBalance = columns.balance !== undefined;
    const idOf = rowIdFactory('myinvestor');
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
            parseCurrencyCode(cellString(cell('currency'))) ?? DEFAULT_CURRENCY;
        const amount = toMinorUnits(value, currency);
        const description = cellString(cell('concept'));
        const balance = hasBalance ? parseSpanishNumber(cell('balance')) : null;

        movements.push({
            date,
            amount,
            currency,
            description,
            externalId: idOf([date, amount, normalizeText(description)]),
            kind: kindOf(description),
            ...(balance === null
                ? {}
                : { balance: toMinorUnits(balance, currency) }),
        });
    }

    return { movements, hasBalance };
}
