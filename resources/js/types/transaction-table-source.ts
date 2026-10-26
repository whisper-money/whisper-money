import { type DaySummaryOf } from '@/lib/transaction-day-groups';
import {
    type TransactionFilters,
    type ServerTransaction,
} from '@/types/transaction';
import { type OnChangeFn, type SortingState } from '@tanstack/react-table';
import { type Dispatch, type SetStateAction } from 'react';

/** The footer below a transactions table: how much it shows, and more. */
export interface TransactionTablePagination {
    /** How many rows the table draws. */
    displayedCount: number;
    /** How many rows there are in all, when the source knows. */
    total?: number;
    rowCountLabel: string;
    hasMore: boolean;
    isLoadingMore: boolean;
    loadMore: () => void;
    /** Load the next page as soon as the button scrolls into view. */
    loadsOnScroll: boolean;
}

/**
 * Where a transactions table gets its rows, and how it filters, sorts, pages
 * and counts them. `/transactions` pages them from the server; the account,
 * budget and savings-goal pages hold all of theirs in memory.
 */
export interface TransactionTableSource {
    /** The rows the table draws. */
    rows: ServerTransaction[];
    /** Every row the source holds, drawn or not. */
    heldRows: ServerTransaction[];
    /** Edits rows in place, after an edit, a delete or a bulk action. */
    setRows: Dispatch<SetStateAction<ServerTransaction[]>>;
    /** The rows are being fetched or recomputed: draw the skeleton. */
    isLoading: boolean;
    filters: TransactionFilters;
    onFiltersChange: (filters: TransactionFilters) => void;
    sorting: SortingState;
    onSortingChange: OnChangeFn<SortingState>;
    /** The source orders the rows itself, so the table must not re-sort them. */
    manualSorting: boolean;
    daySummaryOf: DaySummaryOf;
    pagination: TransactionTablePagination;
    /** Fetches the rows again, after a change the source cannot apply itself. */
    reload: () => void;
    /** Changes when a new query replaces the rows, so the ticked rows go. */
    queryKey: number;
    /**
     * The filters as the server reads them, when the source pages from the
     * server: a bulk action can then reach every row they match.
     */
    matchingFilters?: Record<string, unknown>;
}
