import ImportColumnSuggestionController from '@/actions/App/Http/Controllers/Api/ImportColumnSuggestionController';
import { isAllPositive, type ColumnDescription } from '@/lib/column-shape';
import { detectDateFormat } from '@/lib/file-parser';
import { type BalanceColumnMapping } from '@/types/balance-import';
import {
    type ColumnMapping,
    type DateFormat,
    type ParsedRow,
} from '@/types/import';
import { type UUID } from '@/types/uuid';
import axios from 'axios';

export type SuggestionKind = 'transaction' | 'balance';

/** What the AI answered for one field: the column, by header and position. */
export interface FieldSuggestion {
    column: string | null;
    index: number | null;
    confidence: number;
}

export interface ColumnSuggestions {
    fields: Record<string, FieldSuggestion>;
    /** Served from an earlier identical request rather than asked again. */
    cached: boolean;
}

/** A mapping of field → column, whatever import it is for. */
export type FieldMapping = object;

/** A field of a mapping, read by name. */
function fieldOf(mapping: FieldMapping, field: string): unknown {
    return (mapping as Record<string, unknown>)[field];
}

/**
 * The column the AI picked for each field, or nothing when it could not
 * answer. Only each column's header and shape are sent, never a cell value,
 * and any failure is silent: the caller keeps the mapping it already had.
 */
export async function fetchColumnSuggestions(
    kind: SuggestionKind,
    columns: ColumnDescription[],
    accountId?: UUID | null,
): Promise<ColumnSuggestions | null> {
    if (columns.length === 0) {
        return null;
    }

    try {
        const { data } = await axios.post<{
            data: Record<string, FieldSuggestion> | null;
            cached: boolean;
        }>(ImportColumnSuggestionController.url(), {
            kind,
            columns,
            ...(accountId ? { account_id: accountId } : {}),
        });

        return data.data ? { fields: data.data, cached: data.cached } : null;
    } catch {
        return null;
    }
}

export const TRANSACTION_FIELDS = [
    'transaction_date',
    'description',
    'amount',
    'currency',
    'balance',
    'creditor_name',
    'debtor_name',
    'transaction_type',
] as const;

export const BALANCE_FIELDS = [
    'balance_date',
    'balance',
    'invested_amount',
] as const;

/** The fields an import cannot do without: an AI with no answer leaves them as they were. */
const REQUIRED_FIELDS: Record<SuggestionKind, readonly string[]> = {
    transaction: ['transaction_date', 'description', 'amount'],
    balance: ['balance_date', 'balance'],
};

/**
 * The AI's answer as a mapping to lay over the current one. A required field
 * it could not place is left out, so the header guess still stands; an
 * optional one it found no column for is cleared.
 */
function suggestedColumns(
    suggestions: ColumnSuggestions,
    fields: readonly string[],
    kind: SuggestionKind,
): Record<string, string | null> {
    const mapping: Record<string, string | null> = {};

    for (const field of fields) {
        const column = suggestions.fields[field]?.column ?? null;

        if (column !== null || !REQUIRED_FIELDS[kind].includes(field)) {
            mapping[field] = column;
        }
    }

    return mapping;
}

/** The counterparty fields, which must not repeat the description's text. */
const COUNTERPARTY_FIELDS = ['creditor_name', 'debtor_name'] as const;

/** The columns a mapped field reads, whether one, several or none. */
function columnList(value: unknown): string[] {
    if (Array.isArray(value)) {
        return value.filter(
            (column): column is string => typeof column === 'string',
        );
    }

    return typeof value === 'string' && value !== '' ? [value] : [];
}

/**
 * The transaction mapping the AI suggests. A direction column only matters
 * when the amounts carry no sign, so `transaction_type` is kept only when the
 * suggested amount column is all positive.
 *
 * A counterparty column that is also the description's would copy the
 * description into the payee or payer, so that suggestion is dropped and the
 * field keeps its header guess. The description is the AI's, or
 * `currentDescription` when it placed none. Creditor and debtor may share a
 * column: a combined payer/payee one fills both.
 */
export function suggestedTransactionMapping(
    suggestions: ColumnSuggestions,
    columns: ColumnDescription[],
    currentDescription: ColumnMapping['description'] = null,
): Partial<ColumnMapping> {
    const mapping = suggestedColumns(
        suggestions,
        TRANSACTION_FIELDS,
        'transaction',
    );
    const descriptionColumns = columnList(
        'description' in mapping ? mapping.description : currentDescription,
    );

    for (const field of COUNTERPARTY_FIELDS) {
        const column = mapping[field];

        if (column && descriptionColumns.includes(column)) {
            delete mapping[field];
        }
    }

    const amountIndex = suggestions.fields.amount?.index;
    const amountShape =
        amountIndex === null || amountIndex === undefined
            ? ''
            : (columns[amountIndex]?.shape ?? '');

    if (!isAllPositive(amountShape)) {
        mapping.transaction_type = null;
    }

    return mapping;
}

/** The balance mapping the AI suggests. */
export function suggestedBalanceMapping(
    suggestions: ColumnSuggestions,
): Partial<BalanceColumnMapping> {
    return suggestedColumns(suggestions, BALANCE_FIELDS, 'balance');
}

/** Only the fields the AI places, out of a stored mapping that carries more. */
export function pickFields(
    mapping: FieldMapping,
    fields: readonly string[],
): FieldMapping {
    return Object.fromEntries(
        fields.map((field) => [field, fieldOf(mapping, field) ?? null]),
    );
}

/** One column, several joined, "" and null all compared as what they name. */
function columnsOf(value: unknown): string {
    if (Array.isArray(value)) {
        return value.filter(Boolean).join('\u0000');
    }

    return typeof value === 'string' ? value : '';
}

/** How many of the suggested fields the mapping no longer has as suggested. */
export function changedSuggestedFields(
    mapping: FieldMapping,
    suggested: FieldMapping,
): number {
    return Object.keys(suggested).filter(
        (field) =>
            columnsOf(fieldOf(mapping, field)) !==
            columnsOf(fieldOf(suggested, field)),
    ).length;
}

/**
 * The suggestion laid over the mapping, except on the fields the user has
 * already picked by hand: it arrives after the mapping is on screen.
 */
export function mergeSuggestion<M extends FieldMapping>(
    mapping: M,
    suggested: Partial<M>,
    touched: ReadonlySet<string>,
): M {
    const merged: M = { ...mapping };

    for (const [field, column] of Object.entries(suggested)) {
        if (!touched.has(field)) {
            (merged as Record<string, unknown>)[field] = column;
        }
    }

    return merged;
}

/** The date format a column reads with, as far as its values tell. */
export interface DateFormatGuess {
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
}

/**
 * The suggestion laid over the mapping (see {@link mergeSuggestion}), and the
 * format its date column reads with when the suggestion moved it to another
 * one; null when it did not, or the new column's dates tell nothing.
 */
export function applySuggestion<M extends FieldMapping>(
    mapping: M,
    suggested: Partial<M>,
    keep: ReadonlySet<string>,
    dateField: keyof M & string,
    rows: ParsedRow[],
    locale?: string,
): { mapping: M; format: DateFormatGuess | null } {
    const merged = mergeSuggestion(mapping, suggested, keep);
    const column = merged[dateField] as string | null;
    const detected =
        column && column !== mapping[dateField]
            ? detectDateFormat(rows, column, locale)
            : null;

    return {
        mapping: merged,
        format: detected
            ? {
                  dateFormat: detected.format,
                  dateFormatDetected: !detected.ambiguous,
              }
            : null,
    };
}
