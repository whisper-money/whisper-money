import ImportColumnSuggestionController from '@/actions/App/Http/Controllers/Api/ImportColumnSuggestionController';
import { type ColumnDescription } from '@/lib/column-shape';
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

/** Fields an import cannot do without: an AI with no answer leaves them as they were. */
const REQUIRED_FIELDS = new Set([
    'transaction_date',
    'description',
    'amount',
    'balance_date',
    'balance',
]);

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
        // A balance import's `balance` is required; a transaction's is not.
        const required =
            REQUIRED_FIELDS.has(field) &&
            !(kind === 'transaction' && field === 'balance');

        if (column !== null || !required) {
            mapping[field] = column;
        }
    }

    return mapping;
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

/**
 * The transaction mapping the AI suggests. A direction column only matters
 * when the amounts carry no sign, so `transaction_type` is kept only when the
 * suggested amount column is all positive (the rule that took the eval from
 * 93% to 99%).
 */
export function suggestedTransactionMapping(
    suggestions: ColumnSuggestions,
    columns: ColumnDescription[],
): Partial<ColumnMapping> {
    const mapping = suggestedColumns(
        suggestions,
        TRANSACTION_FIELDS,
        'transaction',
    );
    const amountIndex = suggestions.fields.amount?.index;
    const amountShape =
        amountIndex === null || amountIndex === undefined
            ? ''
            : (columns[amountIndex]?.shape ?? '');

    if (!amountShape.includes('all positive')) {
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

/** The date format a newly picked date column reads with, when it can tell. */
export function redetectDateFormat(
    rows: ParsedRow[],
    column: string | null,
    locale?: string,
): { dateFormat: DateFormat; dateFormatDetected: boolean } | null {
    const detected = column ? detectDateFormat(rows, column, locale) : null;

    return detected
        ? {
              dateFormat: detected.format,
              dateFormatDetected: !detected.ambiguous,
          }
        : null;
}
