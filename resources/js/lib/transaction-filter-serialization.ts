import { type TransactionFilters } from '@/types/transaction';
import { format } from 'date-fns';

/** Persisted, snake_case representation of a filter set (matches the backend query params). */
export interface SerializedFilters {
    date_from?: string;
    date_to?: string;
    amount_min?: number;
    amount_max?: number;
    category_ids?: string[];
    account_ids?: string[];
    label_ids?: string[];
    creditor_name?: string;
    debtor_name?: string;
    search?: string;
}

export function serializeFilters(
    filters: TransactionFilters,
): SerializedFilters {
    const result: SerializedFilters = {};

    if (filters.dateFrom) {
        result.date_from = format(filters.dateFrom, 'yyyy-MM-dd');
    }
    if (filters.dateTo) {
        result.date_to = format(filters.dateTo, 'yyyy-MM-dd');
    }
    if (filters.amountMin !== null) {
        result.amount_min = filters.amountMin;
    }
    if (filters.amountMax !== null) {
        result.amount_max = filters.amountMax;
    }
    if (filters.categoryIds.length > 0) {
        result.category_ids = filters.categoryIds;
    }
    if (filters.accountIds.length > 0) {
        result.account_ids = filters.accountIds;
    }
    if (filters.labelIds.length > 0) {
        result.label_ids = filters.labelIds;
    }
    if (filters.creditorName) {
        result.creditor_name = filters.creditorName;
    }
    if (filters.debtorName) {
        result.debtor_name = filters.debtorName;
    }
    if (filters.searchText) {
        result.search = filters.searchText;
    }

    return result;
}

export function deserializeFilters(
    data: SerializedFilters,
): TransactionFilters {
    return {
        dateFrom: data.date_from
            ? new Date(data.date_from + 'T00:00:00')
            : null,
        dateTo: data.date_to ? new Date(data.date_to + 'T00:00:00') : null,
        amountMin: data.amount_min ?? null,
        amountMax: data.amount_max ?? null,
        categoryIds: data.category_ids ?? [],
        accountIds: data.account_ids ?? [],
        labelIds: data.label_ids ?? [],
        creditorName: data.creditor_name ?? '',
        debtorName: data.debtor_name ?? '',
        searchText: data.search ?? '',
    };
}

export function hasActiveFilters(filters: TransactionFilters): boolean {
    return Object.keys(serializeFilters(filters)).length > 0;
}

/**
 * Stable, order-insensitive fingerprint of a serialized filter set, so two
 * filter sets that select the same things compare equal regardless of the
 * order ids were added or which empty keys are present.
 */
export function filtersFingerprint(filters: SerializedFilters): string {
    return JSON.stringify({
        date_from: filters.date_from ?? null,
        date_to: filters.date_to ?? null,
        amount_min: filters.amount_min ?? null,
        amount_max: filters.amount_max ?? null,
        category_ids: [...(filters.category_ids ?? [])].sort(),
        account_ids: [...(filters.account_ids ?? [])].sort(),
        label_ids: [...(filters.label_ids ?? [])].sort(),
        creditor_name: filters.creditor_name ?? '',
        debtor_name: filters.debtor_name ?? '',
        search: filters.search ?? '',
    });
}
