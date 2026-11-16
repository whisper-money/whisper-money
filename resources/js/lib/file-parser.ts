import {
    DateFormat,
    type ColumnMapping,
    type ParsedRow,
    type ParsedTransaction,
    type TypeValueCount,
} from '@/types/import';
import { formatLocalDate } from '@/utils/date';
import * as XLSX from 'xlsx';

/**
 * What the reader rejects with when the browser cannot read the file at all, so
 * a caller can tell "not readable" apart from a file that parsed to nothing.
 */
export const UNREADABLE_FILE_MESSAGE = 'Failed to read file';

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

const UTF8_BOM = new Uint8Array([0xef, 0xbb, 0xbf]);

function readBytes(file: File): Promise<Uint8Array<ArrayBuffer>> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () =>
            resolve(new Uint8Array(reader.result as ArrayBuffer));
        reader.onerror = () => reject(reader.error);
        reader.readAsArrayBuffer(file);
    });
}

/**
 * The spreadsheet reader takes a CSV without a byte order mark for Latin-1,
 * so a UTF-8 export from a Spanish app arrives as "CategorÃ­as" and every
 * accented description with it. A CSV that is valid UTF-8 and has anything
 * past ASCII in it gets the mark it was missing; anything else is left alone.
 */
async function withUtf8ByteOrderMark(file: File): Promise<File> {
    if (!file.name.toLowerCase().endsWith('.csv')) {
        return file;
    }

    const bytes = await readBytes(file);
    const hasBom = UTF8_BOM.every((byte, index) => bytes[index] === byte);

    if (hasBom || !bytes.some((byte) => byte > 0x7f)) {
        return file;
    }

    try {
        new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch {
        return file;
    }

    return new File([UTF8_BOM, bytes], file.name, { type: file.type });
}

export async function parseFile(file: File): Promise<{
    headers: string[];
    data: ParsedRow[];
    rowNumbers: number[];
    columns: unknown[][];
    headerRowIndex: number;
}> {
    let fileToRead: File;

    try {
        fileToRead = await withUtf8ByteOrderMark(file);
    } catch {
        // Reading the bytes fails the same way the reader below would: a
        // folder named like a CSV is the usual case.
        throw new Error(UNREADABLE_FILE_MESSAGE);
    }

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
            reject(new Error(UNREADABLE_FILE_MESSAGE));
        };

        reader.readAsBinaryString(fileToRead);
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

function leadsWithFourDigitYear(dateValue: ParsedRow[string]): boolean {
    return /^\d{4}\D/.test(String(dateValue).trim());
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

    const sampledValues = data
        .slice(0, sampleSize)
        .map((row) => row[dateColumnName])
        .filter(Boolean);

    // Dates that name their month read the same in every format: "1 dic 2025"
    // is no guess, only the order the format picker shows changes.
    const named = sampledValues.map((value) =>
        readNamedMonthDate(stripTimeOfDay(String(value).trim())),
    );

    if (named.length > 0 && named.every((one) => one?.date)) {
        return {
            format: named.every((one) => one?.monthFirst)
                ? DateFormat.MonthDayYear
                : DateFormat.DayMonthYear,
            ambiguous: false,
        };
    }

    for (const dateValue of sampledValues) {
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

    // "25/10/17" reads as 2025-10-17 too, but a date that doesn't open with a
    // four-digit year almost always starts with the day or the month.
    const candidates = sampledValues.every(leadsWithFourDigitYear)
        ? tiedFormats
        : tiedFormats.filter((format) => format !== DateFormat.YearMonthDay);

    // Multiple formats parse the sample equally well (e.g. 02/06/2026 is valid
    // as both DD/MM and MM/DD). Pick the locale-preferred format as the default
    // but flag it as ambiguous so the caller can let the user confirm.
    const localePreferred = getLocaleDateFormat(locale);
    if (localePreferred && candidates.includes(localePreferred)) {
        return { format: localePreferred, ambiguous: true };
    }

    return { format: candidates[0] ?? tiedFormats[0], ambiguous: true };
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
        transaction_type: null,
        expense_types: [],
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
    const transactionTypePatterns = [
        'type',
        'transaction type',
        'movement type',
        'tipo',
        'tipo de transacción',
        'tipo de movimiento',
        'tipo de operación',
        'debit/credit',
        'credit/debit',
        'dr/cr',
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
        ['transaction_type', transactionTypePatterns],
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
            // A type column turns amounts negative, so it is only claimed by a
            // header that is one of its patterns: "type" alone would also take
            // "Card type", and "tipo" would take "Tipo de cambio".
            const length =
                field === 'transaction_type'
                    ? patterns.includes(header.trim())
                        ? header.trim().length
                        : 0
                    : longestMatch(patterns, header);

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
    mapping.transaction_type = claims.transaction_type?.header ?? null;

    return mapping;
}

/**
 * Type values that plainly mean money going out, compared once trimmed and
 * lowercased. A plural ("gastos", "debits") counts too.
 */
const EXPENSE_TYPE_VALUES = [
    'gasto',
    'expense',
    'debit',
    'débito',
    'debito',
    'cargo',
    'withdrawal',
    'dépense',
    'out',
    'dr',
    // Dutch (ING's "Af Bij") and German direction columns.
    'af',
    'soll',
];

function normalizeTypeValue(value: string): string {
    return value.trim().toLowerCase();
}

/** Whether two type values are the same once trimmed and lowercased. */
export function isSameTypeValue(a: string, b: string): boolean {
    return normalizeTypeValue(a) === normalizeTypeValue(b);
}

/** A cell as text, trimmed; blank for a missing column or an empty cell. */
function rawCell(row: ParsedRow, column: string | null): string {
    if (!column) {
        return '';
    }

    const value = row[column];

    return value === null || value === undefined ? '' : String(value).trim();
}

/**
 * The distinct values of the type column, most common first, each as first
 * written in the file and with how many rows carry it. Blank cells are left out.
 */
export function collectTypeValues(
    rows: ParsedRow[],
    column: string | null | undefined,
): TypeValueCount[] {
    if (!column) {
        return [];
    }

    const values = new Map<string, TypeValueCount>();

    for (const row of rows) {
        const value = rawCell(row, column);

        if (value === '') {
            continue;
        }

        const key = normalizeTypeValue(value);
        const entry = values.get(key);

        if (entry) {
            entry.count++;
        } else {
            values.set(key, { value, count: 1 });
        }
    }

    return [...values.values()].sort((a, b) => b.count - a.count);
}

/** The values of the type column worth ticking as expenses before the user looks. */
export function detectExpenseTypes(
    rows: ParsedRow[],
    column: string | null | undefined,
): string[] {
    return collectTypeValues(rows, column)
        .map(({ value }) => value)
        .filter((value) => {
            const normalized = normalizeTypeValue(value);

            return EXPENSE_TYPE_VALUES.some(
                (expense) =>
                    normalized === expense || normalized === `${expense}s`,
            );
        });
}

/**
 * What gives each row's amount the sign the type column says: an expense row
 * is always negative, whatever sign the file wrote; any other row keeps its
 * own. Without a type column, or with no value ticked as an expense, amounts
 * are untouched.
 */
export function typeSigner(
    mapping: ColumnMapping,
): (amount: number, row: ParsedRow) => number {
    const column = mapping.transaction_type;
    const expenseTypes = new Set(
        (mapping.expense_types ?? []).map(normalizeTypeValue),
    );

    if (!column || expenseTypes.size === 0) {
        return (amount) => amount;
    }

    return (amount, row) =>
        expenseTypes.has(normalizeTypeValue(rawCell(row, column)))
            ? -Math.abs(amount)
            : amount;
}

// A time of day trailing the date: "25/10/17, 1:47", "2026-10-01T12:30:00Z",
// "01/10/2026 1:47:05.123 p. m. +02:00", "10/08/26 20:04h", "10/08/26 20:04 CEST".
// Only the calendar day is imported, so the time and any offset are dropped
// as written, without converting.
const TRAILING_TIME_OF_DAY =
    /(?:,\s*|\s+|T)\d{1,2}:\d{2}(?::\d{2}(?:[.,]\d+)?)?(?:\s*[ap]\.?\s?m\.?)?(?:\s*h)?(?:\s*(?:Z|[+-]\d{2}(?::?\d{2})?|[a-z]{2,5}))?$/i;

function stripTimeOfDay(dateStr: string): string {
    return dateStr.replace(TRAILING_TIME_OF_DAY, '').trim();
}

// The years the server stores (App\Rules\StorableDate). A year outside them is
// not one someone wrote: a time the strip above misses loses its separators
// with everything else and glues onto the year ("10/08/26 20:04h" read as year
// 262004 before the strip knew the "h"). Returning null leaves the row out like
// any unreadable date instead of posting it for the server to refuse
// (PHP-LARAVEL-7D).
const MIN_STORABLE_YEAR = 1900;
const MAX_STORABLE_YEAR = 9999;

function isStorableYear(year: number): boolean {
    return year >= MIN_STORABLE_YEAR && year <= MAX_STORABLE_YEAR;
}

// Month names as Spanish and English exports write them, abbreviated or in
// full ("1 dic 2025, 0:00:00" from Money Pro in Spanish, "Dec 1, 2025").
// Keys are lower case without accents.
const MONTH_NUMBERS: Record<string, number> = {
    ...monthNames(
        ['ene', 'feb', 'mar', 'abr', 'may', 'jun'],
        ['jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
    ),
    ...monthNames(
        ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio'],
        ['julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
    ),
    ...monthNames(
        ['jan', 'feb', 'mar', 'apr', 'may', 'jun'],
        ['jul', 'aug', 'sep', 'oct', 'nov', 'dec'],
    ),
    ...monthNames(
        ['january', 'february', 'march', 'april', 'may', 'june'],
        ['july', 'august', 'september', 'october', 'november', 'december'],
    ),
    sept: 9,
    setiembre: 9,
};

function monthNames(
    firstHalf: string[],
    secondHalf: string[],
): Record<string, number> {
    return Object.fromEntries(
        [...firstHalf, ...secondHalf].map((name, index) => [name, index + 1]),
    );
}

// "1 dic 2025", "01-dic-25", "1 de diciembre de 2025", "1 Dec. 2025".
const DAY_MONTH_NAME_YEAR =
    /^(\d{1,2})(?:\s+de\s+|\s*[-./\s]\s*)(\p{L}+)\.?(?:\s+de\s+|\s*[-./,\s]\s*)(\d{4}|\d{2})$/u;

// "Dec 1, 2025", "December 1 2025".
const MONTH_NAME_DAY_YEAR = /^(\p{L}+)\.?\s+(\d{1,2}),?\s+(\d{4})$/u;

interface NamedMonthDate {
    date: Date | null;
    monthFirst: boolean;
}

function monthNumber(name: string): number | undefined {
    return MONTH_NUMBERS[
        name.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
    ];
}

/**
 * A date that writes its month by name, read the same whatever numeric
 * format was picked. Null when the text is not shaped like one, so a date
 * written in digits never reads here.
 */
function readNamedMonthDate(value: string): NamedMonthDate | null {
    const dayFirst = DAY_MONTH_NAME_YEAR.exec(value);
    const monthFirst = dayFirst ? null : MONTH_NAME_DAY_YEAR.exec(value);
    const [, day, name, year] = dayFirst ?? [];
    const [, monthFirstName, monthFirstDay, monthFirstYear] = monthFirst ?? [];
    const month = monthNumber(name ?? monthFirstName ?? '');

    if (month === undefined) {
        return null;
    }

    return {
        date: calendarDate(
            Number(year ?? monthFirstYear),
            month,
            Number(day ?? monthFirstDay),
        ),
        monthFirst: monthFirst !== null,
    };
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

    let str = stripTimeOfDay(String(dateStr).trim());
    const named = readNamedMonthDate(str);

    if (named) {
        return named.date;
    }

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

    return calendarDate(year, month, day);
}

// "01/12/2025", "2025-12-01", "01.12.25", "01/12": nothing but digits and
// the separators a date is written with.
const DIGIT_DATE = /^\d{1,4}[./-]\d{1,2}(?:[./-]\d{1,4})?$/;
const COMPACT_DIGIT_DATE = /^(?:19|20)\d{6}$/;
const DIGIT_DATE_FORMATS = [
    DateFormat.YearMonthDay,
    DateFormat.DayMonthYear,
    DateFormat.MonthDayYear,
];

/**
 * Whether a text is, all on its own, a date this parser reads in some
 * format: in digits ("01/12/2025", "20251201", "01/12") or with its month by
 * name ("1 dic 2025", "1 de diciembre de 2025", "Dec 1, 2025"), with or
 * without a time of day. A word with a number in it ("Saldo 2024") is not.
 */
export function isDateText(value: string): boolean {
    const text = stripTimeOfDay(value.trim());

    if (readNamedMonthDate(text)?.date) {
        return true;
    }

    if (COMPACT_DIGIT_DATE.test(text)) {
        return parseDate(text, DateFormat.YearMonthDayCompact) !== null;
    }

    return (
        DIGIT_DATE.test(text) &&
        DIGIT_DATE_FORMATS.some((format) => parseDate(text, format) !== null)
    );
}

/**
 * The day a year, month and day name, or null when they name none the server
 * stores. A missing year is this year's; a two-digit one is 1950–2049.
 */
function calendarDate(
    year: number | undefined,
    month: number | undefined,
    day: number | undefined,
): Date | null {
    if (year === undefined) {
        year = new Date().getFullYear();
    }

    if (year < 100) {
        year += year < 50 ? 2000 : 1900;
    }

    if (month === undefined || day === undefined) {
        return null;
    }

    const date = new Date(year, month - 1, day);

    if (
        isNaN(date.getTime()) ||
        !isStorableYear(year) ||
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return null;
    }

    return date;
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
    const signAmount = typeSigner(mapping);

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
        const signedAmount = signAmount(amount, row);
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
            amount: Math.round(signedAmount * 100),
            balance,
            creditor_name: creditorName,
            debtor_name: debtorName,
            validationErrors: [],
        });
    }

    return results;
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
