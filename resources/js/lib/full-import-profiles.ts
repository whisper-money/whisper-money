import {
    detectDateFormat,
    parseAmount,
    parseCurrencyCode,
    parseDate,
} from '@/lib/file-parser';
import {
    parseImportFile,
    type ParsedImportFile,
} from '@/lib/transaction-import';
import {
    SINGLE_ACCOUNT_COLUMN,
    type FullImportMapping,
    type FullImportSource,
    type MappedColumn,
    type NormalizedFile,
    type NormalizedRow,
    type SavedProfile,
    type UnreadableRow,
} from '@/types/full-import';
import { DateFormat, type ParsedRow } from '@/types/import';
import { formatLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

/**
 * Lower case, no accents, every run of anything that is not a letter or a
 * digit as one space. What two names are compared on: "Producto - Nombre" and
 * "producto nombre" are the same column, "Categorías" and "categorias" the
 * same category.
 */
export function normalizeText(value: unknown): string {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

/** The trimmed text of a cell, or '' when the column is unmapped or empty. */
export function cellText(row: ParsedRow, column: string | null): string {
    if (!column || column === SINGLE_ACCOUNT_COLUMN) {
        return '';
    }

    const value = row[column];

    return value === null || value === undefined ? '' : String(value).trim();
}

/** Every column the mapping can point at, in the order the Columns step lists them. */
export const MAPPED_COLUMNS: readonly MappedColumn[] = [
    'date',
    'amount',
    'description',
    'account',
    'accountDetail',
    'notes',
    'category',
    'currency',
    'balance',
    'iban',
    'externalId',
    'ignored',
];

/**
 * Banktrack's export, by the normalised name of each header. The `ID` column
 * is Banktrack's own id for the movement, which is what makes a second import
 * of the same file a no-op.
 */
const BANKTRACK_HEADERS: Record<MappedColumn, string> = {
    date: 'fecha',
    amount: 'importe',
    description: 'concepto',
    account: 'banco',
    accountDetail: 'producto nombre',
    notes: 'descripcion',
    category: 'categorias',
    currency: 'moneda',
    balance: 'balance',
    iban: 'producto iban',
    externalId: 'id',
    ignored: 'ignorada',
};

/** The headers a file must carry to be read as a Banktrack export. */
const BANKTRACK_SIGNATURE = [
    'fecha',
    'concepto',
    'importe',
    'banco',
    'categorias',
    'ignorada',
    'id',
];

/** What a generic file's account, category and notes columns tend to be called. */
const GENERIC_GUESSES: Partial<Record<MappedColumn, string[]>> = {
    account: ['account', 'cuenta', 'bank', 'banco'],
    category: ['category', 'categories', 'categoria', 'categorias'],
    notes: ['notes', 'note', 'notas', 'nota', 'memo'],
};

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
export async function withUtf8ByteOrderMark(file: File): Promise<File> {
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

/** Read a file for the full import: the shared reader, minus its encoding trap. */
export async function readFullImportFile(
    file: File,
    locale?: string,
): Promise<ParsedImportFile> {
    const parsed = await parseImportFile(
        await withUtf8ByteOrderMark(file),
        locale,
    );

    return { ...parsed, file };
}

function headerLookup(headers: string[]): Map<string, string> {
    const byName = new Map<string, string>();

    for (const header of headers) {
        const normalized = normalizeText(header);

        if (!byName.has(normalized)) {
            byName.set(normalized, header);
        }
    }

    return byName;
}

/** Banktrack when the file carries its headers, otherwise nothing to say. */
export function detectSource(headers: string[]): FullImportSource | null {
    const byName = headerLookup(headers);

    return BANKTRACK_SIGNATURE.every((name) => byName.has(name))
        ? 'banktrack'
        : null;
}

function emptyMapping(dateFormat: DateFormat): FullImportMapping {
    return {
        date: null,
        amount: null,
        description: null,
        account: null,
        accountDetail: null,
        splitByAccountDetail: false,
        notes: null,
        category: null,
        categorySeparator: ',',
        currency: null,
        balance: null,
        iban: null,
        externalId: null,
        ignored: null,
        dateFormat,
    };
}

function banktrackMapping(parsed: ParsedImportFile): FullImportMapping {
    const byName = headerLookup(parsed.headers);
    const mapping = emptyMapping(DateFormat.DayMonthYear);

    for (const field of MAPPED_COLUMNS) {
        mapping[field] = byName.get(BANKTRACK_HEADERS[field]) ?? null;
    }

    return mapping;
}

function genericMapping(parsed: ParsedImportFile): FullImportMapping {
    const detected = parsed.mapping;
    const mapping = emptyMapping(parsed.dateFormat);
    const description = Array.isArray(detected.description)
        ? (detected.description[0] ?? null)
        : detected.description;

    mapping.date = detected.transaction_date;
    mapping.amount = detected.amount;
    mapping.description = description;
    mapping.currency = detected.currency;
    mapping.balance = detected.balance;

    const taken = new Set(
        [mapping.date, mapping.amount, mapping.description].filter(Boolean),
    );

    for (const [field, patterns] of Object.entries(GENERIC_GUESSES)) {
        const header = parsed.headers.find(
            (one) => !taken.has(one) && patterns.includes(normalizeText(one)),
        );

        if (header) {
            mapping[field as MappedColumn] = header;
            taken.add(header);
        }
    }

    return mapping;
}

/**
 * The mapping a source starts from. Banktrack's layout is known, so it is
 * filled in whole; anything else gets the shared column detection plus a
 * guess at the account, category and notes columns.
 */
export function defaultMapping(
    source: FullImportSource,
    parsed: ParsedImportFile,
    locale?: string,
): FullImportMapping {
    if (source === 'generic') {
        return genericMapping(parsed);
    }

    const mapping = banktrackMapping(parsed);

    if (mapping.date) {
        const detected = detectDateFormat(parsed.rows, mapping.date, locale);

        if (detected && !detected.ambiguous) {
            mapping.dateFormat = detected.format;
        }
    }

    return mapping;
}

const DATE_FORMATS = Object.values(DateFormat) as string[];

/**
 * Lay the mapping the source was last imported with over the defaults. A
 * stored layout naming a column this file does not have belongs to another
 * export, and is ignored whole.
 */
export function applySavedProfile(
    mapping: FullImportMapping,
    profile: SavedProfile | null,
    headers: string[],
): FullImportMapping {
    if (!profile?.columns) {
        return mapping;
    }

    const columns = Object.entries(profile.columns).filter(
        ([field, column]) =>
            column !== null && MAPPED_COLUMNS.includes(field as MappedColumn),
    );
    const fits = columns.every(
        ([, column]) =>
            column === SINGLE_ACCOUNT_COLUMN ||
            headers.includes(column as string),
    );

    if (!fits) {
        return mapping;
    }

    return {
        ...mapping,
        ...Object.fromEntries(columns),
        dateFormat:
            profile.date_format && DATE_FORMATS.includes(profile.date_format)
                ? (profile.date_format as DateFormat)
                : mapping.dateFormat,
        categorySeparator:
            profile.category_separator ?? mapping.categorySeparator,
        splitByAccountDetail:
            profile.split_accounts ?? mapping.splitByAccountDetail,
    };
}

/** The mapping in the shape the server stores for next time. */
export function toSavedProfile(mapping: FullImportMapping): SavedProfile {
    return {
        columns: Object.fromEntries(
            MAPPED_COLUMNS.map((field) => [field, mapping[field]]),
        ),
        date_format: mapping.dateFormat,
        category_separator: mapping.categorySeparator,
        split_accounts: mapping.splitByAccountDetail,
    };
}

/** The headers the current mapping leaves out of the import. */
export function unmappedHeaders(
    mapping: FullImportMapping,
    headers: string[],
): string[] {
    const used = new Set(MAPPED_COLUMNS.map((field) => mapping[field]));

    if (!mapping.splitByAccountDetail) {
        used.delete(mapping.accountDetail);
    }

    return headers.filter((header) => !used.has(header));
}

/**
 * "Empresa, Gastos Empresa" as ['Empresa', 'Gastos Empresa']. The tree is
 * three levels deep at most, so anything past the third stays in the third.
 */
export function splitCategoryPath(value: string, separator: string): string[] {
    const trimmed = value.trim();

    if (trimmed === '') {
        return [];
    }

    if (separator === '') {
        return [trimmed];
    }

    const parts = trimmed
        .split(separator)
        .map((part) => part.trim())
        .filter((part) => part !== '');

    return parts.length > 3
        ? [parts[0], parts[1], parts.slice(2).join(`${separator} `)]
        : parts;
}

/** Banktrack writes TRUE/FALSE; spreadsheets say yes, 1 or x as often. */
export function parseIgnoredFlag(value: string): boolean {
    return /^(true|verdadero|1|yes|si|sí|x)$/i.test(value.trim());
}

/**
 * The fully blank rows the reader dropped, from the gaps they left in the row
 * numbers. Banktrack puts one between blocks of movements now and then.
 */
export function countBlankRows(rowNumbers: number[]): number {
    let blank = 0;

    for (let index = 1; index < rowNumbers.length; index++) {
        blank += Math.max(0, rowNumbers[index] - rowNumbers[index - 1] - 1);
    }

    return blank;
}

function sameText(a: string, b: string): boolean {
    return (
        a.replace(/\s+/g, ' ').toLowerCase() ===
        b.replace(/\s+/g, ' ').toLowerCase()
    );
}

/** Why a row cannot be read, or null when it can. */
function unreadableReason(
    row: ParsedRow,
    mapping: FullImportMapping,
    date: Date | null,
    amount: number | null,
    description: string,
): string | null {
    if (!date) {
        return cellText(row, mapping.date)
            ? __("Date can't be read")
            : __('No date');
    }

    if (amount === null) {
        return cellText(row, mapping.amount)
            ? __("Amount can't be read")
            : __('No amount');
    }

    return description === '' ? __('No description') : null;
}

interface NormalizeOptions {
    /** Names the one account of a file mapped as a single account. */
    fileName: string;
    supportedCurrencies: readonly string[];
}

function accountOf(
    row: ParsedRow,
    mapping: FullImportMapping,
    fileName: string,
): { key: string; name: string; bank: string } {
    if (mapping.account === SINGLE_ACCOUNT_COLUMN) {
        return {
            key: 'single',
            name: fileName.replace(/\.[^.]+$/, '') || __('Imported account'),
            bank: '',
        };
    }

    const value = cellText(row, mapping.account);
    const detail = mapping.splitByAccountDetail
        ? cellText(row, mapping.accountDetail)
        : '';
    const key = mapping.splitByAccountDetail
        ? `${normalizeText(value)}|${normalizeText(detail)}`
        : normalizeText(value);
    const base = value || __('No account');

    return { key, name: detail ? `${base} · ${detail}` : base, bank: value };
}

function balanceOf(row: ParsedRow, mapping: FullImportMapping): number | null {
    const raw = cellText(row, mapping.balance);

    return raw === '' ? null : parseAmount(raw);
}

function normalizeRow(
    row: ParsedRow,
    rowNumber: number,
    mapping: FullImportMapping,
    options: NormalizeOptions,
): NormalizedRow | UnreadableRow {
    const date = mapping.date
        ? parseDate(row[mapping.date] as string | number, mapping.dateFormat)
        : null;
    const amount = mapping.amount
        ? parseAmount(row[mapping.amount] as string | number)
        : null;
    const notesCell = cellText(row, mapping.notes);
    const description = cellText(row, mapping.description) || notesCell;
    const reason = unreadableReason(row, mapping, date, amount, description);

    if (reason !== null || !date || amount === null) {
        return { rowNumber, reason: reason ?? __('No date') };
    }

    const account = accountOf(row, mapping, options.fileName);

    return {
        rowNumber,
        date: formatLocalDate(date),
        amount,
        description,
        notes:
            notesCell && !sameText(notesCell, description) ? notesCell : null,
        accountKey: account.key,
        accountName: account.name,
        accountBank: account.bank,
        categoryPath: splitCategoryPath(
            cellText(row, mapping.category),
            mapping.categorySeparator,
        ),
        currency: mapping.currency
            ? parseCurrencyCode(
                  row[mapping.currency],
                  options.supportedCurrencies,
              )
            : null,
        balance: balanceOf(row, mapping),
        iban: cellText(row, mapping.iban) || null,
        externalId: cellText(row, mapping.externalId).slice(0, 255) || null,
        ignored: parseIgnoredFlag(cellText(row, mapping.ignored)),
    };
}

/**
 * Read every row of the file with the mapping: the movements it can import,
 * the rows it cannot (and why), and how many blank separator rows it skipped.
 */
export function normalizeRows(
    parsed: Pick<ParsedImportFile, 'rows' | 'rowNumbers'>,
    mapping: FullImportMapping,
    options: NormalizeOptions,
): NormalizedFile {
    const rows: NormalizedRow[] = [];
    const unreadable: UnreadableRow[] = [];

    parsed.rows.forEach((row, index) => {
        const result = normalizeRow(
            row,
            parsed.rowNumbers[index] ?? index + 2,
            mapping,
            options,
        );

        if ('reason' in result) {
            unreadable.push(result);
        } else {
            rows.push(result);
        }
    });

    return { rows, unreadable, blankRows: countBlankRows(parsed.rowNumbers) };
}
