import {
    DateFormat,
    type ColumnMapping,
    type ParsedRow,
    type ParsedTransaction,
} from '@/types/import';
import * as XLSX from 'xlsx';

function detectHeaderRow(columns: unknown[][]): number {
    if (!columns || columns.length === 0) {
        return 0;
    }

    const firstRowWithValue = columns.map((column) =>
        column.findIndex(
            (cell) =>
                cell !== undefined && cell !== null && String(cell).length > 1,
        ),
    );

    const percentages = [0.95, 0.75];

    for (const minPercentage of percentages) {
        const uniqueRows = [...new Set(firstRowWithValue)].sort(
            (a, b) => a - b,
        );

        for (const rowNumber of uniqueRows) {
            if (rowNumber === -1) continue;

            const columnsWithValues = columns.filter((column) => {
                return (
                    column[rowNumber] !== undefined &&
                    column[rowNumber] !== null &&
                    String(column[rowNumber]).length > 1
                );
            }).length;

            if (columnsWithValues / columns.length >= minPercentage) {
                return rowNumber;
            }
        }
    }

    return 0;
}

export async function parseFile(file: File): Promise<{
    headers: string[];
    data: ParsedRow[];
    columns: unknown[][];
    headerRowIndex: number;
}> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const data = e.target?.result;
                if (!data) {
                    reject(new Error('Failed to read file'));
                    return;
                }

                // raw: true keeps text-based cells (CSV) as their original
                // strings instead of letting the parser guess and coerce
                // date-like values into Excel serial numbers. parseDate then
                // applies the user-selected format. Native spreadsheet dates
                // (.xls/.xlsx) still arrive as numbers and use the serial path.
                const workbook = XLSX.read(data, { type: 'binary', raw: true });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];

                const jsonData = XLSX.utils.sheet_to_json(worksheet, {
                    header: 1,
                    raw: true,
                }) as unknown[][];

                if (jsonData.length === 0) {
                    reject(new Error('File is empty'));
                    return;
                }

                const maxColumns = Math.max(
                    ...jsonData.map((row) =>
                        Array.isArray(row) ? row.length : 0,
                    ),
                );
                const columns: unknown[][] = [];

                for (let colIndex = 0; colIndex < maxColumns; colIndex++) {
                    const columnData = jsonData.map((row) =>
                        Array.isArray(row) ? row[colIndex] : undefined,
                    );
                    columns.push(columnData);
                }

                const headerRowIndex = detectHeaderRow(columns);

                const letters = [
                    'A',
                    'B',
                    'C',
                    'D',
                    'E',
                    'F',
                    'G',
                    'H',
                    'I',
                    'J',
                    'K',
                    'L',
                    'M',
                    'N',
                    'O',
                    'P',
                    'Q',
                    'R',
                    'S',
                    'T',
                    'U',
                    'V',
                    'W',
                    'X',
                    'Y',
                    'Z',
                ];

                const headers = columns.map((column, index) => {
                    const headerValue = column[headerRowIndex];
                    const headerStr = String(headerValue || '').trim();

                    if (
                        headerStr &&
                        headerStr.length > 1 &&
                        isNaN(Number(headerStr))
                    ) {
                        return headerStr;
                    }

                    return letters[index] || `Column ${index + 1}`;
                });

                const dataRows = jsonData.slice(
                    headerRowIndex + 1,
                ) as unknown[][];

                const parsedData: ParsedRow[] = dataRows
                    .filter(
                        (row) =>
                            Array.isArray(row) &&
                            row.some(
                                (cell) =>
                                    cell !== null &&
                                    cell !== undefined &&
                                    cell !== '',
                            ),
                    )
                    .map((row) => {
                        const obj: ParsedRow = {};
                        headers.forEach((header, index) => {
                            if (header) {
                                const value = row[index];
                                obj[header] =
                                    value === null || value === undefined
                                        ? null
                                        : (value as string | number);
                            }
                        });
                        return obj;
                    });

                resolve({ headers, data: parsedData, columns, headerRowIndex });
            } catch {
                reject(new Error('Failed to parse file'));
            }
        };

        reader.onerror = () => {
            reject(new Error('Failed to read file'));
        };

        reader.readAsBinaryString(file);
    });
}

/**
 * Returns the preferred date format for a given locale.
 * Most locales use DD-MM-YYYY, while US/Philippines/etc use MM-DD-YYYY.
 */
export function getLocaleDateFormat(locale?: string): DateFormat | null {
    if (!locale) {
        return null;
    }

    const mdyLocales = ['en-US', 'en-PH', 'fil', 'ja', 'zh', 'ko', 'hu'];
    const ymdLocales = [
        'sv',
        'lt',
        'zh-CN',
        'zh-TW',
        'ja-JP',
        'ko-KR',
        'hu-HU',
    ];

    const normalized = locale.replace('_', '-');

    if (ymdLocales.some((l) => normalized.startsWith(l))) {
        return DateFormat.YearMonthDay;
    }

    if (mdyLocales.some((l) => normalized.startsWith(l))) {
        return DateFormat.MonthDayYear;
    }

    return DateFormat.DayMonthYear;
}

export interface DateFormatDetection {
    format: DateFormat;
    ambiguous: boolean;
}

export function detectDateFormat(
    data: ParsedRow[],
    dateColumnName: string,
    locale?: string,
): DateFormatDetection | null {
    if (!data || data.length === 0 || !dateColumnName) {
        return null;
    }

    const formats = [
        DateFormat.YearMonthDay,
        DateFormat.DayMonthYear,
        DateFormat.MonthDayYear,
        DateFormat.YearMonthDayCompact,
    ];
    const sampleSize = Math.min(10, data.length);
    const scores: Record<DateFormat, number> = {
        [DateFormat.YearMonthDay]: 0,
        [DateFormat.DayMonthYear]: 0,
        [DateFormat.MonthDayYear]: 0,
        [DateFormat.YearMonthDayCompact]: 0,
    };

    for (let i = 0; i < sampleSize; i++) {
        const dateValue = data[i][dateColumnName];
        if (!dateValue) continue;

        for (const format of formats) {
            const parsedDate = parseDate(dateValue as string | number, format);
            if (parsedDate) {
                scores[format]++;
            }
        }
    }

    const maxScore = Math.max(...Object.values(scores));

    if (maxScore === 0 || maxScore < sampleSize * 0.8) {
        return null;
    }

    const tiedFormats = formats.filter((format) => scores[format] === maxScore);

    if (tiedFormats.length === 1) {
        return { format: tiedFormats[0], ambiguous: false };
    }

    // Multiple formats parse the sample equally well (e.g. 02/06/2026 is valid
    // as both DD/MM and MM/DD). Pick the locale-preferred format as the default
    // but flag it as ambiguous so the caller can let the user confirm.
    const localePreferred = getLocaleDateFormat(locale);
    if (localePreferred && tiedFormats.includes(localePreferred)) {
        return { format: localePreferred, ambiguous: true };
    }

    return { format: tiedFormats[0], ambiguous: true };
}

export function autoDetectDateFormat(
    data: ParsedRow[],
    dateColumnName: string,
    locale?: string,
): DateFormat | null {
    return detectDateFormat(data, dateColumnName, locale)?.format ?? null;
}

export function autoDetectColumns(headers: string[]): ColumnMapping {
    const mapping: ColumnMapping = {
        transaction_date: null,
        description: null,
        amount: null,
        balance: null,
        creditor_name: null,
        debtor_name: null,
    };

    if (!headers || headers.length === 0) {
        return mapping;
    }

    const lowerHeaders = headers.map((h) => {
        if (h === null || h === undefined) {
            return '';
        }
        return String(h).toLowerCase();
    });

    const datePatterns = [
        'date',
        'transaction date',
        'fecha',
        'transaction_date',
        'trans date',
        'trans_date',
        'f. valor',
    ];
    const descriptionPatterns = [
        'description',
        'desc',
        'descripcion',
        'concept',
        'concepto',
        'details',
        'detalles',
        'memo',
        'descripción',
    ];
    const amountPatterns = [
        'amount',
        'monto',
        'value',
        'valor',
        'total',
        'importe',
        'quantity',
        'cantidad',
    ];
    const balancePatterns = [
        'balance',
        'saldo',
        'current balance',
        'available balance',
        'saldo actual',
        'saldo disponible',
    ];
    const creditorPatterns = [
        'creditor',
        'creditor name',
        'beneficiary',
        'beneficiary name',
        'payee',
        'recipient',
        'contraparte acreedora',
        'acreedor',
    ];
    const debtorPatterns = [
        'debtor',
        'debtor name',
        'payer',
        'sender',
        'originator',
        'ordering party',
        'contraparte deudora',
        'deudor',
    ];

    for (let i = 0; i < lowerHeaders.length; i++) {
        const header = lowerHeaders[i];
        const originalHeader = headers[i];

        if (!header || typeof header !== 'string') {
            continue;
        }

        if (
            !mapping.transaction_date &&
            datePatterns.some((p) => header.includes(p))
        ) {
            mapping.transaction_date = originalHeader;
        }

        if (
            !mapping.description &&
            descriptionPatterns.some((p) => header.includes(p))
        ) {
            mapping.description = originalHeader;
        }

        if (!mapping.amount && amountPatterns.some((p) => header.includes(p))) {
            mapping.amount = originalHeader;
        }

        if (
            !mapping.balance &&
            balancePatterns.some((p) => header.includes(p))
        ) {
            mapping.balance = originalHeader;
        }

        if (
            !mapping.creditor_name &&
            creditorPatterns.some((p) => header.includes(p))
        ) {
            mapping.creditor_name = originalHeader;
        }

        if (
            !mapping.debtor_name &&
            debtorPatterns.some((p) => header.includes(p))
        ) {
            mapping.debtor_name = originalHeader;
        }
    }

    return mapping;
}

export function parseDate(
    dateStr: string | number,
    format: DateFormat,
): Date | null {
    if (!dateStr) {
        return null;
    }

    if (typeof dateStr === 'number') {
        const excelDate = XLSX.SSF.parse_date_code(dateStr);
        if (excelDate) {
            return new Date(excelDate.y, excelDate.m - 1, excelDate.d);
        }
    }

    let str = String(dateStr).trim();
    str = str
        .replace(/\//g, '-')
        .replace(/\./g, '-')
        .replace(/[^\d-]/g, '');

    let year: number | undefined,
        month: number | undefined,
        day: number | undefined;

    if (format === DateFormat.YearMonthDayCompact) {
        const compactArray = /^(\d{4})(\d{2})(\d{2})$/.exec(str);
        if (compactArray) {
            year = Number(compactArray[1]);
            month = Number(compactArray[2]);
            day = Number(compactArray[3]);
        }
    } else if (str.length === 5) {
        const dateRegex = /^(\d{1,2})-(\d{1,2})$/;
        const dateArray = dateRegex.exec(str);
        if (dateArray) {
            month = Number(
                dateArray[format === DateFormat.DayMonthYear ? 2 : 1],
            );
            day = Number(dateArray[format === DateFormat.DayMonthYear ? 1 : 2]);
        }
    } else {
        const parts = str.split('-').filter((p) => p.length > 0);

        if (parts.length === 3) {
            switch (format) {
                case DateFormat.YearMonthDay:
                    [year, month, day] = parts.map(Number);
                    break;
                case DateFormat.MonthDayYear:
                    [month, day, year] = parts.map(Number);
                    break;
                case DateFormat.DayMonthYear:
                    [day, month, year] = parts.map(Number);
                    break;
            }
        } else if (parts.length === 2) {
            month = Number(parts[format === DateFormat.DayMonthYear ? 1 : 0]);
            day = Number(parts[format === DateFormat.DayMonthYear ? 0 : 1]);
        }
    }

    if (year === undefined) {
        year = new Date().getFullYear();
    }

    if (year < 100) {
        year += year < 50 ? 2000 : 1900;
    }

    if (year === undefined || month === undefined || day === undefined) {
        return null;
    }

    const date = new Date(year, month - 1, day);

    if (
        isNaN(date.getTime()) ||
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return null;
    }

    return date;
}

function formatLocalDate(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function parseAmount(amountStr: string | number): number | null {
    if (typeof amountStr === 'number') {
        return amountStr;
    }

    if (!amountStr) {
        return null;
    }

    let str = String(amountStr).trim();

    const isNegative = /^-/.test(str) || /^\(.*\)$/.test(str);

    const dotPos = str.lastIndexOf('.');
    const commaPos = str.lastIndexOf(',');

    const decimalSep =
        dotPos > commaPos && dotPos !== -1
            ? dotPos
            : commaPos > dotPos && commaPos !== -1
              ? commaPos
              : -1;

    if (decimalSep !== -1) {
        const integerPart = str.substring(0, decimalSep).replace(/[^\d]/g, '');
        const decimalPart = str.substring(decimalSep + 1);
        str = integerPart + '.' + decimalPart;
    } else {
        str = str.replace(/[^\d]/g, '');
    }

    const amount = parseFloat(str);

    if (isNaN(amount)) {
        return null;
    }

    return isNegative ? -Math.abs(amount) : amount;
}

function getDescriptionFromRow(row: ParsedRow, mapping: ColumnMapping): string {
    if (!mapping.description) {
        return '';
    }

    const columns = Array.isArray(mapping.description)
        ? mapping.description
        : [mapping.description];

    return columns
        .map((col) => String(row[col] || '').trim())
        .filter((val) => val.length > 0)
        .join('\n');
}

function getOptionalTextFromRow(
    row: ParsedRow,
    column: string | null,
): string | null {
    if (!column) {
        return null;
    }

    const value = String(row[column] || '').trim();

    return value.length > 0 ? value.slice(0, 255) : null;
}

export function validateTransaction(
    row: ParsedRow,
    mapping: ColumnMapping,
    dateFormat: DateFormat,
): { isValid: boolean; errors: string[] } {
    const errors: string[] = [];

    if (!mapping.transaction_date || !row[mapping.transaction_date]) {
        errors.push('Missing transaction date');
    } else {
        const date = parseDate(
            row[mapping.transaction_date] as string | number,
            dateFormat,
        );
        if (!date) {
            errors.push('Invalid date format');
        }
    }

    if (!mapping.description) {
        errors.push('Missing description');
    } else {
        const description = getDescriptionFromRow(row, mapping);
        if (!description) {
            errors.push('Missing description');
        }
    }

    if (
        !mapping.amount ||
        row[mapping.amount] === null ||
        row[mapping.amount] === undefined
    ) {
        errors.push('Missing amount');
    } else {
        const amount = parseAmount(row[mapping.amount] as string | number);
        if (amount === null) {
            errors.push('Invalid amount format');
        }
    }

    return {
        isValid: errors.length === 0,
        errors,
    };
}

export function convertRowsToTransactions(
    rows: ParsedRow[],
    mapping: ColumnMapping,
    dateFormat: DateFormat,
): ParsedTransaction[] {
    const results: ParsedTransaction[] = [];

    for (const row of rows) {
        const validation = validateTransaction(row, mapping, dateFormat);

        if (!validation.isValid) {
            continue;
        }

        const date = parseDate(
            row[mapping.transaction_date!] as string | number,
            dateFormat,
        );
        const amount = parseAmount(row[mapping.amount!] as string | number);
        const description = getDescriptionFromRow(row, mapping);

        if (!date || amount === null || !description) {
            continue;
        }

        const formattedDate = formatLocalDate(date);
        const creditorName = getOptionalTextFromRow(row, mapping.creditor_name);
        const debtorName = getOptionalTextFromRow(row, mapping.debtor_name);

        let balance: number | null = null;
        if (mapping.balance) {
            const rawBalance = row[mapping.balance];
            if (
                rawBalance !== null &&
                rawBalance !== undefined &&
                String(rawBalance).trim() !== ''
            ) {
                const parsedBalance = parseAmount(
                    rawBalance as string | number,
                );
                if (parsedBalance !== null) {
                    balance = Math.round(parsedBalance * 100);
                }
            }
        }

        results.push({
            transaction_date: formattedDate,
            description,
            amount: Math.round(amount * 100),
            balance,
            creditor_name: creditorName,
            debtor_name: debtorName,
            validationErrors: [],
        });
    }

    return results;
}

/**
 * Find the latest transaction date (YYYY-MM-DD) from parsed rows using the
 * provided column mapping and date format. Returns null if no valid dates.
 */
export function getLatestTransactionDate(
    rows: ParsedRow[],
    mapping: ColumnMapping,
    dateFormat: DateFormat,
): string | null {
    if (!mapping.transaction_date) {
        return null;
    }

    let latest: Date | null = null;

    for (const row of rows) {
        const raw = row[mapping.transaction_date];
        if (raw === null || raw === undefined || raw === '') {
            continue;
        }
        const parsed = parseDate(raw as string | number, dateFormat);
        if (!parsed) {
            continue;
        }
        if (!latest || parsed.getTime() > latest.getTime()) {
            latest = parsed;
        }
    }

    return latest ? formatLocalDate(latest) : null;
}

/**
 * Given a chronologically sorted list of transactions (any order) and the
 * balance as of the latest transaction date, compute the end-of-day balance
 * for every distinct date by walking backwards: subtract each day's net
 * movement from the next day's balance.
 *
 * All amounts/balances are in cents.
 */
export function calculateBalancesFromTransactions(
    transactions: ParsedTransaction[],
    latestDate: string,
    referenceBalance: number,
): Map<string, number> {
    const dailyNet = new Map<string, number>();

    for (const txn of transactions) {
        dailyNet.set(
            txn.transaction_date,
            (dailyNet.get(txn.transaction_date) ?? 0) + txn.amount,
        );
    }

    const dates = Array.from(dailyNet.keys()).sort();
    const balances = new Map<string, number>();

    if (dates.length === 0) {
        balances.set(latestDate, referenceBalance);
        return balances;
    }

    if (!dailyNet.has(latestDate)) {
        dates.push(latestDate);
        dates.sort();
    }

    balances.set(latestDate, referenceBalance);

    const latestIndex = dates.indexOf(latestDate);

    for (let i = latestIndex - 1; i >= 0; i--) {
        const nextDate = dates[i + 1];
        const nextNet = dailyNet.get(nextDate) ?? 0;
        const nextBalance = balances.get(nextDate) ?? 0;
        balances.set(dates[i], nextBalance - nextNet);
    }

    return balances;
}

/**
 * Build the map of balances to store per date from imported transactions.
 *
 * CSV rows are newest-on-top and imported top-to-bottom, so when several
 * transactions share a date the first one encountered is the newest and holds
 * the correct end-of-day balance. A balance of 0 (or negative) is valid and
 * kept; only null/undefined balances are skipped.
 */
export function collectBalancesToImport(
    transactions: ParsedTransaction[],
): Map<string, number> {
    const balances = new Map<string, number>();

    for (const transaction of transactions) {
        if (
            transaction.balance !== null &&
            transaction.balance !== undefined &&
            !balances.has(transaction.transaction_date)
        ) {
            balances.set(transaction.transaction_date, transaction.balance);
        }
    }

    return balances;
}
