import {
    DateFormat,
    type ColumnMapping,
    type MappedField,
    type MappingReport,
    type ParsedRow,
    type ParsedTransaction,
    type RowFault,
    type RowProblem,
} from '@/types/import';
import { toMinorUnits } from '@/utils/currency';
import * as XLSX from 'xlsx';

export const UNREADABLE_FILE_MESSAGE =
    'This file could not be read. If it is a Numbers file saved as a package, open it in Numbers and use File > Save As to save it as a single file.';

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

/**
 * Apple Numbers date cells reach us as `Date` objects, where CSV and Excel
 * dates arrive as text or as a serial number. Numbers counts seconds from
 * 2001-01-01 UTC but SheetJS adds them to a *local* 2001-01-01, so the instant
 * it hands back is shifted by this timezone's offset on that day — enough to
 * land on the wrong calendar day east of Greenwich or in southern-hemisphere
 * winter. Shifting it back by that same offset recovers the day the user typed.
 *
 * The result is an ISO date string so the rest of the importer keeps seeing one
 * cell type it already understands, previews included.
 *
 * Only Numbers reaches this: SheetJS builds `Date` cells for the other formats
 * only under `cellDates`, which the read below leaves off. Turning that on
 * would send Excel dates through this shift too, and they do not need it.
 */
function spreadsheetDateToIsoDate(value: Date): string {
    const localEpochOffset =
        Date.UTC(2001, 0, 1) - new Date(2001, 0, 1).getTime();

    return new Date(value.getTime() + localEpochOffset)
        .toISOString()
        .slice(0, 10);
}

export async function parseFile(file: File): Promise<{
    headers: string[];
    data: ParsedRow[];
    rowNumbers: number[];
    columns: unknown[][];
    headerRowIndex: number;
}> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const data = e.target?.result;
                if (!data) {
                    reject(new Error(UNREADABLE_FILE_MESSAGE));
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

                const jsonData = (
                    XLSX.utils.sheet_to_json(worksheet, {
                        header: 1,
                        raw: true,
                    }) as unknown[][]
                ).map((row) =>
                    Array.isArray(row)
                        ? row.map((cell) =>
                              cell instanceof Date
                                  ? spreadsheetDateToIsoDate(cell)
                                  : cell,
                          )
                        : row,
                );

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

                const parsedData: ParsedRow[] = [];
                const rowNumbers: number[] = [];

                dataRows.forEach((row, index) => {
                    const hasValue =
                        Array.isArray(row) &&
                        row.some(
                            (cell) =>
                                cell !== null &&
                                cell !== undefined &&
                                cell !== '',
                        );

                    if (!hasValue) {
                        return;
                    }

                    const obj: ParsedRow = {};
                    headers.forEach((header, columnIndex) => {
                        if (header) {
                            const value = row[columnIndex];
                            obj[header] =
                                value === null || value === undefined
                                    ? null
                                    : (value as string | number);
                        }
                    });

                    parsedData.push(obj);
                    // Empty rows are dropped, so the index no longer matches the
                    // file. Keep the row's own number for anything the user has
                    // to go and find in their spreadsheet.
                    rowNumbers.push(headerRowIndex + index + 2);
                });

                resolve({
                    headers,
                    data: parsedData,
                    rowNumbers,
                    columns,
                    headerRowIndex,
                });
            } catch {
                reject(new Error('Failed to parse file'));
            }
        };

        reader.onerror = () => {
            // A folder, or a Numbers file saved as a macOS package, is a
            // directory: the browser hands over a File it can never read.
            reject(new Error(UNREADABLE_FILE_MESSAGE));
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
        currency: null,
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
    const currencyPatterns = [
        'currency',
        'currency_code',
        'moneda',
        'divisa',
        'ccy',
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

    // Each header is claimed by a single field: the one whose longest pattern
    // matches it, and on a tie the field listed first below. Amount is last
    // because its patterns are the most generic: 'valor' is the real amount
    // header in Portuguese exports, but in "Fecha valor" it is a date column
    // and must not end up holding a date rendered as a number.
    const fieldPatterns = [
        ['transaction_date', datePatterns],
        ['description', descriptionPatterns],
        ['currency', currencyPatterns],
        ['balance', balancePatterns],
        ['creditor_name', creditorPatterns],
        ['debtor_name', debtorPatterns],
        ['amount', amountPatterns],
    ] as const;

    const longestMatch = (
        patterns: readonly string[],
        header: string,
    ): number => {
        return Math.max(
            0,
            ...patterns
                .filter((pattern) => header.includes(pattern))
                .map((pattern) => pattern.length),
        );
    };

    const claims: Record<string, { header: string; length: number }> = {};

    lowerHeaders.forEach((header, index) => {
        let claimedField: { field: string; length: number } | null = null;

        for (const [field, patterns] of fieldPatterns) {
            const length = longestMatch(patterns, header);

            if (
                length > 0 &&
                (claimedField === null || length > claimedField.length)
            ) {
                claimedField = { field, length };
            }
        }

        if (claimedField === null) {
            return;
        }

        const current = claims[claimedField.field];

        if (current === undefined || claimedField.length > current.length) {
            claims[claimedField.field] = {
                header: headers[index],
                length: claimedField.length,
            };
        }
    });

    mapping.transaction_date = claims.transaction_date?.header ?? null;
    mapping.description = claims.description?.header ?? null;
    mapping.amount = claims.amount?.header ?? null;
    mapping.currency = claims.currency?.header ?? null;
    mapping.balance = claims.balance?.header ?? null;
    mapping.creditor_name = claims.creditor_name?.header ?? null;
    mapping.debtor_name = claims.debtor_name?.header ?? null;

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

/**
 * The calendar day a Date names locally. `toISOString()` cannot stand in for
 * this: parseDate builds local midnight, which is the day before in UTC for
 * anywhere east of Greenwich.
 */
export function formatLocalDate(date: Date): string {
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

    // A sign only counts at either edge: leading it may sit behind a currency
    // symbol or code ("€ -50,32"), trailing it must follow the digits
    // ("50,32-"). Hyphens between digits are date separators ("31-07-2026")
    // and a trailing "1'234.-" means zero cents, not a negative sign.
    const isNegative =
        /^\D*[-\u2212\u2013]/.test(str) ||
        /\d\s*[-\u2212\u2013]\D*$/.test(str) ||
        /^\(.*\)$/.test(str);

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

/**
 * Normalise a currency cell to an ISO 4217 code. Returns null when the value is
 * not a three-letter code, or when `supportedCurrencies` is given and does not
 * contain it, so the caller falls back to the account currency.
 */
export function parseCurrencyCode(
    value: string | number | null | undefined,
    supportedCurrencies?: readonly string[],
): string | null {
    const code = String(value ?? '')
        .trim()
        .toUpperCase();

    if (!/^[A-Z]{3}$/.test(code)) {
        return null;
    }

    if (supportedCurrencies && !supportedCurrencies.includes(code)) {
        return null;
    }

    return code;
}

/**
 * The distinct currencies found in a mapped currency column, split into the
 * codes the app supports and the raw values it does not recognise. Used to tell
 * the user which rows will fall back to the account currency.
 */
export function collectCurrencyCodes(
    rows: ParsedRow[],
    column: string | null,
    supportedCurrencies: readonly string[],
): { supported: string[]; unsupported: string[] } {
    const supported = new Set<string>();
    const unsupported = new Set<string>();

    if (!column) {
        return { supported: [], unsupported: [] };
    }

    for (const row of rows) {
        const raw = String(row[column] ?? '').trim();

        if (raw === '') {
            continue;
        }

        const code = parseCurrencyCode(raw, supportedCurrencies);

        if (code) {
            supported.add(code);
        } else {
            unsupported.add(raw.toUpperCase());
        }
    }

    return { supported: [...supported], unsupported: [...unsupported] };
}

/**
 * Whether the transaction holds the account's own money. Balances belong to the
 * account and are held in its currency, so a row in another currency says
 * nothing about them. A row without a currency defaults to the account's.
 */
export function isInAccountCurrency(
    transaction: ParsedTransaction,
    accountCurrency: string,
): boolean {
    return (transaction.currency_code ?? accountCurrency) === accountCurrency;
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
    accountCurrency: string,
    supportedCurrencies?: readonly string[],
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
                    balance = toMinorUnits(parsedBalance, accountCurrency);
                }
            }
        }

        const rowCurrency = mapping.currency
            ? parseCurrencyCode(row[mapping.currency], supportedCurrencies)
            : null;

        results.push({
            transaction_date: formattedDate,
            description,
            amount: toMinorUnits(amount, rowCurrency ?? accountCurrency),
            currency_code: rowCurrency,
            balance,
            creditor_name: creditorName,
            debtor_name: debtorName,
            validationErrors: [],
        });
    }

    return results;
}

function rawCell(row: ParsedRow, column: string | null): string {
    if (!column) {
        return '';
    }

    const value = row[column];

    return value === null || value === undefined ? '' : String(value).trim();
}

/**
 * What the current mapping would make of the whole file.
 *
 * The import itself drops any row it cannot read — no date, no description, no
 * amount — so this is what tells the user it happened, and which rows, before
 * they commit. A row is `skipped` as soon as one of those three fails, and
 * `adjusted` when it will import but not as written, which today only means a
 * currency falling back to the account's.
 */
export function buildMappingReport(
    rows: ParsedRow[],
    rowNumbers: number[],
    mapping: ColumnMapping,
    dateFormat: DateFormat,
    accountCurrency: string,
    supportedCurrencies?: readonly string[],
): MappingReport {
    const fields: MappingReport['fields'] = {
        transaction_date: { ok: 0, skipped: 0, adjusted: 0 },
        description: { ok: 0, skipped: 0, adjusted: 0 },
        amount: { ok: 0, skipped: 0, adjusted: 0 },
        currency: { ok: 0, skipped: 0, adjusted: 0 },
    };
    const problems: RowProblem[] = [];
    const used = new Set<string>();
    const fallback = new Set<string>();

    let from: string | null = null;
    let to: string | null = null;
    let min: number | null = null;
    let max: number | null = null;
    let descriptionSample: string | null = null;
    let readyCount = 0;
    let adjustedCount = 0;

    rows.forEach((row, index) => {
        const faults: RowFault[] = [];

        const date = mapping.transaction_date
            ? parseDate(
                  row[mapping.transaction_date] as string | number,
                  dateFormat,
              )
            : null;

        if (date) {
            fields.transaction_date.ok++;
            const iso = formatLocalDate(date);
            if (from === null || iso < from) {
                from = iso;
            }
            if (to === null || iso > to) {
                to = iso;
            }
        } else {
            fields.transaction_date.skipped++;
            faults.push({
                field: 'transaction_date',
                reason: rawCell(row, mapping.transaction_date)
                    ? "Date can't be read"
                    : 'No date',
                severity: 'skipped',
            });
        }

        const description = getDescriptionFromRow(row, mapping);

        if (description) {
            fields.description.ok++;
            descriptionSample = descriptionSample ?? description;
        } else {
            fields.description.skipped++;
            faults.push({
                field: 'description',
                reason: 'No description',
                severity: 'skipped',
            });
        }

        const amount = mapping.amount
            ? parseAmount(row[mapping.amount] as string | number)
            : null;

        if (amount === null) {
            fields.amount.skipped++;
            faults.push({
                field: 'amount',
                reason: rawCell(row, mapping.amount)
                    ? "Amount can't be read"
                    : 'No amount',
                severity: 'skipped',
            });
        } else {
            fields.amount.ok++;
            const minorUnits = toMinorUnits(amount, accountCurrency);
            if (min === null || minorUnits < min) {
                min = minorUnits;
            }
            if (max === null || minorUnits > max) {
                max = minorUnits;
            }
        }

        if (mapping.currency) {
            const raw = rawCell(row, mapping.currency);
            const code = parseCurrencyCode(raw, supportedCurrencies);

            if (code) {
                fields.currency.ok++;
                used.add(code);
            } else {
                fields.currency.adjusted++;
                fallback.add(raw === '' ? '(empty)' : raw.toUpperCase());
                faults.push({
                    field: 'currency',
                    reason: `Will import as ${accountCurrency}`,
                    severity: 'adjusted',
                });
            }
        }

        const skipped = faults.some((fault) => fault.severity === 'skipped');

        // A skipped row never reaches the import, so its currency fallback is
        // not something the user has to weigh.
        if (!skipped) {
            readyCount++;
            if (faults.length > 0) {
                adjustedCount++;
            }
        }

        if (faults.length > 0) {
            problems.push({
                rowNumber: rowNumbers[index] ?? index + 1,
                cells: {
                    transaction_date: rawCell(row, mapping.transaction_date),
                    description: getDescriptionFromRow(row, mapping),
                    amount: rawCell(row, mapping.amount),
                    currency: rawCell(row, mapping.currency),
                },
                faults,
                severity: skipped ? 'skipped' : 'adjusted',
            });
        }
    });

    return {
        total: rows.length,
        readyCount,
        skippedCount: rows.length - readyCount,
        adjustedCount,
        fields,
        dateRange: from !== null && to !== null ? { from, to } : null,
        amountRange: min !== null && max !== null ? { min, max } : null,
        descriptionSample,
        currencies: { used: [...used], fallback: [...fallback] },
        problems,
    };
}

/** The fields the mapping table reports on, in the order it shows them. */
export const REPORTED_FIELDS: readonly MappedField[] = [
    'transaction_date',
    'description',
    'amount',
    'currency',
];

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
