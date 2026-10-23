import {
    mergeSuggestion,
    redetectDateFormat,
    suggestedTransactionMapping,
    type ColumnSuggestions,
} from '@/lib/ai-import-mapping';
import { type ColumnDescription } from '@/lib/column-shape';
import {
    detectDateFormat,
    parseAmount,
    parseCurrencyCode,
    parseDate,
} from '@/lib/file-parser';
import { type ParsedImportFile } from '@/lib/transaction-import';
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
const BANKTRACK_HEADERS: Partial<Record<MappedColumn, string>> = {
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

/**
 * Money Pro's export (in Spanish), by the normalised name of each header. The
 * type, received amount and destination account columns have no field of
 * their own: the Money Pro reader finds them by name.
 */
const MONEY_PRO_HEADERS: Partial<Record<MappedColumn, string>> = {
    date: 'fecha',
    amount: 'cantidad',
    description: 'descripcion',
    account: 'cuenta',
    category: 'categoria',
    balance: 'saldo',
};

/** The headers a file must carry to be read as a Money Pro export. */
const MONEY_PRO_SIGNATURE = [
    'fecha',
    'cantidad',
    'cuenta',
    'monto recibido',
    'cuenta de destino',
    'saldo',
    'tipo de transaccion',
];

/** What a generic file's account, category and notes columns tend to be called. */
const GENERIC_GUESSES: Partial<Record<MappedColumn, string[]>> = {
    account: ['account', 'cuenta', 'bank', 'banco'],
    category: ['category', 'categories', 'categoria', 'categorias'],
    notes: ['notes', 'note', 'notas', 'nota', 'memo'],
};

/** Each header of the file by its normalised name, the first one winning. */
export function headerLookup(headers: string[]): Map<string, string> {
    const byName = new Map<string, string>();

    for (const header of headers) {
        const normalized = normalizeText(header);

        if (!byName.has(normalized)) {
            byName.set(normalized, header);
        }
    }

    return byName;
}

/** The app whose headers the file carries, or nothing to say. */
export function detectSource(headers: string[]): FullImportSource | null {
    const byName = headerLookup(headers);
    const carries = (signature: string[]) =>
        signature.every((name) => byName.has(name));

    if (carries(BANKTRACK_SIGNATURE)) {
        return 'banktrack';
    }

    return carries(MONEY_PRO_SIGNATURE) ? 'money_pro' : null;
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
        currencySymbols: {},
        currency: null,
        balance: null,
        iban: null,
        externalId: null,
        ignored: null,
        dateFormat,
    };
}

/** The mapping of an app whose headers are known, by their normalised names. */
function knownMapping(
    parsed: ParsedImportFile,
    known: Partial<Record<MappedColumn, string>>,
): FullImportMapping {
    const byName = headerLookup(parsed.headers);
    const mapping = emptyMapping(DateFormat.DayMonthYear);

    for (const field of MAPPED_COLUMNS) {
        const name = known[field];

        mapping[field] = name ? (byName.get(name) ?? null) : null;
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
 * The mapping a source starts from. Banktrack's and Money Pro's layouts are
 * known, so they are filled in whole; anything else gets the shared column
 * detection plus a guess at the account, category and notes columns.
 */
export function defaultMapping(
    source: FullImportSource,
    parsed: ParsedImportFile,
    locale?: string,
): FullImportMapping {
    if (source === 'generic') {
        return genericMapping(parsed);
    }

    const mapping =
        source === 'money_pro'
            ? {
                  ...knownMapping(parsed, MONEY_PRO_HEADERS),
                  categorySeparator: ':',
              }
            : knownMapping(parsed, BANKTRACK_HEADERS);

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
    if (!profile || !savedProfileFits(profile, headers)) {
        return mapping;
    }

    const columns = profileColumns(profile);

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

/** The columns a stored layout names, leaving out the fields it left empty. */
function profileColumns(profile: SavedProfile): [string, string | null][] {
    return Object.entries(profile.columns ?? {}).filter(
        ([field, column]) =>
            column !== null && MAPPED_COLUMNS.includes(field as MappedColumn),
    );
}

/** Every column the stored layout names is one this file has. */
export function savedProfileFits(
    profile: SavedProfile | null,
    headers: string[],
): boolean {
    return (
        !!profile?.columns &&
        profileColumns(profile).every(
            ([, column]) =>
                column === SINGLE_ACCOUNT_COLUMN ||
                headers.includes(column as string),
        )
    );
}

/** The full import's fields the AI places, by the transaction field it answers for. */
const SUGGESTED_FIELDS = {
    date: 'transaction_date',
    amount: 'amount',
    description: 'description',
    currency: 'currency',
    balance: 'balance',
} as const;

/**
 * The AI's answer as the full import's mapping: the fields every bank export
 * shares. Account, category and notes stay with the header guesses.
 */
export function suggestedFullImportMapping(
    suggestions: ColumnSuggestions,
    columns: ColumnDescription[],
): Partial<FullImportMapping> {
    const suggested = suggestedTransactionMapping(suggestions, columns);
    const mapping: Partial<Record<MappedColumn, string | null>> = {};

    for (const [field, from] of Object.entries(SUGGESTED_FIELDS)) {
        if (from in suggested) {
            mapping[field as MappedColumn] = suggested[from] as string | null;
        }
    }

    return mapping;
}

/**
 * Lay the AI's suggestion over the mapping, leaving the fields in `keep` as
 * the user picked them. A new date column reads with its own format.
 */
export function applyFullImportSuggestion(
    mapping: FullImportMapping,
    rows: ParsedRow[],
    suggested: Partial<FullImportMapping>,
    keep: ReadonlySet<string>,
    locale?: string,
): FullImportMapping {
    const merged = mergeSuggestion(mapping, suggested, keep);
    const format =
        merged.date !== mapping.date
            ? redetectDateFormat(rows, merged.date, locale)
            : null;

    return format?.dateFormatDetected
        ? { ...merged, dateFormat: format.dateFormat }
        : merged;
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
 * numbers. Banktrack puts one between blocks of movements now and then. With
 * several files read as one, numbering starts over in each, so a gap only
 * counts between two rows of the same file.
 */
export function countBlankRows(
    rowNumbers: number[],
    rowFiles?: string[],
): number {
    let blank = 0;

    for (let index = 1; index < rowNumbers.length; index++) {
        if (rowFiles && rowFiles[index] !== rowFiles[index - 1]) {
            continue;
        }

        blank += Math.max(0, rowNumbers[index] - rowNumbers[index - 1] - 1);
    }

    return blank;
}

/** Whether two texts read the same, case and runs of spaces aside. */
export function sameText(a: string, b: string): boolean {
    return (
        a.replace(/\s+/g, ' ').toLowerCase() ===
        b.replace(/\s+/g, ' ').toLowerCase()
    );
}

/** Why a row cannot be read, or null when it can. */
export function unreadableReason(
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

export interface NormalizeOptions {
    /** Names the one account of a file mapped as a single account. */
    fileName: string;
    supportedCurrencies: readonly string[];
    /** What an ambiguous currency symbol ("$") is read as first. */
    userCurrency?: string;
}

/**
 * Reads one row of the file into what it imports: usually one movement, two
 * for a transfer between the user's own accounts, none for a row that
 * carries nothing.
 */
export type RowReader = (
    row: ParsedRow,
    rowNumber: number,
    mapping: FullImportMapping,
    options: NormalizeOptions,
) => (NormalizedRow | UnreadableRow)[];

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

export function balanceOf(
    row: ParsedRow,
    mapping: FullImportMapping,
): number | null {
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
        kind: 'movement',
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
    parsed: Pick<ParsedImportFile, 'rows' | 'rowNumbers' | 'rowFiles'>,
    mapping: FullImportMapping,
    options: NormalizeOptions,
    readRow: RowReader = (...args) => [normalizeRow(...args)],
): NormalizedFile {
    const rows: NormalizedRow[] = [];
    const unreadable: UnreadableRow[] = [];

    parsed.rows.forEach((row, index) => {
        const results = readRow(
            row,
            parsed.rowNumbers[index] ?? index + 2,
            mapping,
            options,
        );

        for (const result of results) {
            if (!('reason' in result)) {
                rows.push(result);

                continue;
            }

            unreadable.push(
                parsed.rowFiles
                    ? { ...result, fileName: parsed.rowFiles[index] }
                    : result,
            );
        }
    });

    return {
        rows,
        unreadable,
        blankRows: countBlankRows(parsed.rowNumbers, parsed.rowFiles),
    };
}
