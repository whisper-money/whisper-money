import { DateFormat } from './import';
import type { UUID } from './uuid';

export enum BalanceImportStep {
    SelectAccount = 'select-account',
    UploadFile = 'upload-file',
    MapColumns = 'map-columns',
    Preview = 'preview',
}

export interface BalanceColumnMapping {
    balance_date: string | null;
    balance: string | null;
}

export interface ParsedBalance {
    balance_date: string;
    balance: number;
}

export interface ColumnOption {
    value: string;
    label: string;
    examples: (string | number)[];
}

export interface ParsedRow {
    [key: string]: string | number | null;
}

export interface BalanceImportState {
    step: BalanceImportStep;
    selectedAccountId: UUID | null;
    file: File | null;
    parsedData: ParsedRow[];
    columnHeaders: string[];
    columnOptions: ColumnOption[];
    columnMapping: BalanceColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    balances: ParsedBalance[];
}
