import { UUID } from './uuid';

export enum ImportStep {
    SelectAccount = 'select-account',
    UploadFile = 'upload-file',
    MapColumns = 'map-columns',
    Preview = 'preview',
}

export enum DateFormat {
    YearMonthDay = 'YYYY-MM-DD',
    MonthDayYear = 'MM-DD-YYYY',
    DayMonthYear = 'DD-MM-YYYY',
    YearMonthDayCompact = 'YYYYMMDD',
}

export interface ColumnMapping {
    transaction_date: string | null;
    description: string | string[] | null;
    amount: string | null;
    currency: string | null;
    balance: string | null;
    creditor_name: string | null;
    debtor_name: string | null;
}

/** The fields a file column can be mapped to and then reported on. */
export type MappedField =
    | 'transaction_date'
    | 'description'
    | 'amount'
    | 'currency';

/** Why one row's cell could not be taken as written. */
export interface RowFault {
    field: MappedField;
    reason: string;
    /** `skipped` drops the row; `adjusted` imports it with a different value. */
    severity: 'skipped' | 'adjusted';
}

/** A row worth showing the user, with the cells they need to recognise it. */
export interface RowProblem {
    /** The row's own number in the file, so it can be found in a spreadsheet. */
    rowNumber: number;
    cells: Record<MappedField, string>;
    faults: RowFault[];
    /** `skipped` as soon as one fault drops the row. */
    severity: 'skipped' | 'adjusted';
}

/**
 * What the current mapping would make of the whole file: how many rows each
 * field could be read from, what those values look like, and every row that
 * will not import as written.
 */
export interface MappingReport {
    total: number;
    readyCount: number;
    skippedCount: number;
    adjustedCount: number;
    fields: Record<
        MappedField,
        { ok: number; skipped: number; adjusted: number }
    >;
    /** ISO bounds of the parsed dates: what the chosen date format produced. */
    dateRange: { from: string; to: string } | null;
    /** Cent bounds of the parsed amounts. */
    amountRange: { min: number; max: number } | null;
    /** The first description the mapping could build. */
    descriptionSample: string | null;
    /** Codes kept as they stand, and the raw values falling back to the account. */
    currencies: { used: string[]; fallback: string[] };
    problems: RowProblem[];
}

export interface ParsedRow {
    [key: string]: string | number | null;
}

export interface ParsedTransaction {
    transaction_date: string;
    description: string;
    amount: number;
    /** Null when the row has no currency of its own: the account's is used. */
    currency_code?: string | null;
    balance?: number | null;
    creditor_name?: string | null;
    debtor_name?: string | null;
    isDuplicate?: boolean;
    selected?: boolean;
    validationErrors?: string[];
}

export interface ColumnOption {
    value: string;
    label: string;
    examples: (string | number)[];
}

export interface ImportState {
    step: ImportStep;
    selectedAccountId: UUID | null;
    file: File | null;
    parsedData: ParsedRow[];
    /** Each parsed row's own number in the file, aligned with `parsedData`. */
    rowNumbers: number[];
    columnHeaders: string[];
    columnOptions: ColumnOption[];
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    transactions: ParsedTransaction[];
    calculateBalances: boolean;
    referenceBalance: number | null;
    referenceBalanceDate: string | null;
    referenceBalancePrefilled: boolean;
}
