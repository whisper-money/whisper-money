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
}

export interface ColumnMapping {
    transaction_date: string | null;
    description: string | null;
    amount: string | null;
    balance: string | null;
}

export interface ParsedRow {
    [key: string]: string | number | null;
}

export interface ParsedTransaction {
    transaction_date: string;
    description: string;
    amount: number;
    balance?: number | null;
    isDuplicate?: boolean;
    validationErrors?: string[];
}

export interface ColumnOption {
    value: string;
    label: string;
    examples: (string | number)[];
}

export interface ImportState {
    step: ImportStep;
    selectedAccountId: number | null;
    file: File | null;
    parsedData: ParsedRow[];
    columnHeaders: string[];
    columnOptions: ColumnOption[];
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    transactions: ParsedTransaction[];
}
