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
    /** A column holding each row's own currency, for files mixing several. */
    currency: string | null;
    balance: string | null;
    creditor_name: string | null;
    debtor_name: string | null;
    /**
     * A column saying which way each row's money went, for files whose amounts
     * are all positive. Missing from layouts saved before it existed.
     */
    transaction_type?: string | null;
    /** The values of `transaction_type` whose rows are expenses, as written in the file. */
    expense_types?: string[];
}

/** One distinct value of the type column, as first written, and how many rows carry it. */
export interface TypeValueCount {
    value: string;
    count: number;
}

export interface ParsedRow {
    [key: string]: string | number | null;
}

export interface ParsedTransaction {
    transaction_date: string;
    description: string;
    amount: number;
    balance?: number | null;
    creditor_name?: string | null;
    debtor_name?: string | null;
    isDuplicate?: boolean;
    selected?: boolean;
    /** Set once the row is in: a retry of the same preview must not resend it. */
    imported?: boolean;
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
    columnHeaders: string[];
    /** The file has a real header row: its headers may be sent to the AI. */
    hasHeaderRow: boolean;
    columnOptions: ColumnOption[];
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    transactions: ParsedTransaction[];
}
