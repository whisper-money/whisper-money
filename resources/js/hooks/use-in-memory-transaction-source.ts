import { useInMemoryDaySummaries } from '@/hooks/use-day-summaries';
import { reloadPage } from '@/lib/leave-page';
import {
    type TransactionFilters as Filters,
    type ServerTransaction,
    type Transaction,
} from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { type UUID } from '@/types/uuid';
import { toMajorUnits } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { type SortingState } from '@tanstack/react-table';
import { isWithinInterval, parseISO } from 'date-fns';
import { useCallback, useEffect, useMemo, useState } from 'react';

function matchesFilters(
    transaction: ServerTransaction,
    filters: Filters,
    accountId: UUID | undefined,
    searchMatchedIds: Set<string>,
): boolean {
    // When scoped to a single account, drop rows that were reassigned to
    // another account (e.g. via inline edit). The list no longer
    // refetches, so without this they would linger until a full reload.
    if (accountId && transaction.account_id !== accountId) {
        return false;
    }

    if (filters.searchText && !searchMatchedIds.has(transaction.id)) {
        return false;
    }

    if (filters.dateFrom || filters.dateTo) {
        const transactionDate = parseISO(transaction.transaction_date);
        if (
            filters.dateFrom &&
            filters.dateTo &&
            !isWithinInterval(transactionDate, {
                start: filters.dateFrom,
                end: filters.dateTo,
            })
        ) {
            return false;
        }
        if (filters.dateFrom && transactionDate < filters.dateFrom) {
            return false;
        }
        if (filters.dateTo && transactionDate > filters.dateTo) {
            return false;
        }
    }

    const amountInMajorUnits = toMajorUnits(
        transaction.amount,
        transaction.currency_code,
    );

    if (filters.amountMin !== null && amountInMajorUnits < filters.amountMin) {
        return false;
    }
    if (filters.amountMax !== null && amountInMajorUnits > filters.amountMax) {
        return false;
    }

    if (
        filters.categoryIds.length > 0 &&
        !filters.categoryIds.includes(transaction.category_id || -1)
    ) {
        return false;
    }

    if (
        !accountId &&
        filters.accountIds.length > 0 &&
        !filters.accountIds.includes(transaction.account_id)
    ) {
        return false;
    }

    if (
        filters.creditorName &&
        !transaction.creditor_name
            ?.toLowerCase()
            .includes(filters.creditorName.toLowerCase())
    ) {
        return false;
    }

    if (
        filters.debtorName &&
        !transaction.debtor_name
            ?.toLowerCase()
            .includes(filters.debtorName.toLowerCase())
    ) {
        return false;
    }

    return true;
}

function compareBy(
    id: string,
    a: ServerTransaction,
    b: ServerTransaction,
): number {
    if (id === 'transaction_date') {
        return (
            parseISO(a.transaction_date).getTime() -
            parseISO(b.transaction_date).getTime()
        );
    }
    if (id === 'amount') {
        return parseFloat(a.amount) - parseFloat(b.amount);
    }
    if (id === 'description') {
        return a.description.localeCompare(b.description);
    }
    if (id === 'account') {
        return (a.account?.name || '').localeCompare(b.account?.name || '');
    }
    if (id === 'category') {
        return (a.category?.name || '').localeCompare(b.category?.name || '');
    }
    if (id === 'creditor_name') {
        return (a.creditor_name || '').localeCompare(b.creditor_name || '');
    }
    if (id === 'debtor_name') {
        return (a.debtor_name || '').localeCompare(b.debtor_name || '');
    }

    return 0;
}

function sortRows(
    rows: ServerTransaction[],
    sorting: SortingState,
): ServerTransaction[] {
    if (sorting.length === 0) {
        return rows;
    }

    return [...rows].sort((a, b) => {
        for (const { id, desc } of sorting) {
            const comparison = compareBy(id, a, b);

            if (comparison !== 0) {
                return desc ? -comparison : comparison;
            }
        }
        return 0;
    });
}

/**
 * A transactions table over rows the page already holds: it filters and sorts
 * them in the browser, draws them a page at a time, and counts each day over
 * every row the filters keep.
 */
export function useInMemoryTransactionSource({
    transactions: providedTransactions,
    accountId,
    pageSize = 25,
}: {
    /** The rows, as the page received them; undefined while they load. */
    transactions?: Transaction[];
    /** Keeps the list to one account, the filter for it hidden. */
    accountId?: UUID;
    pageSize?: number;
}): TransactionTableSource {
    const [transactions, setTransactions] = useState<ServerTransaction[]>([]);
    const [isLoading, setIsLoading] = useState(
        providedTransactions === undefined,
    );
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'transaction_date', desc: true },
    ]);
    const [filters, setFilters] = useState<Filters>({
        dateFrom: null,
        dateTo: null,
        amountMin: null,
        amountMax: null,
        categoryIds: [],
        accountIds: accountId ? [accountId] : [],
        labelIds: [],
        creditorName: '',
        debtorName: '',
        searchText: '',
        aiCategorizedOnly: false,
    });
    const [displayedCount, setDisplayedCount] = useState(pageSize);
    const [isLoadingMore, setIsLoadingMore] = useState(false);

    useEffect(() => {
        setIsLoading(true);
        try {
            const processed = (providedTransactions ?? []).map(
                (transaction) =>
                    ({
                        ...transaction,
                        description: transaction.description,
                        notes: transaction.notes || null,
                        label_ids:
                            transaction.label_ids ??
                            (
                                transaction as Partial<ServerTransaction>
                            ).labels?.map((label) => label.id) ??
                            [],
                    }) as ServerTransaction,
            );

            setTransactions(processed);
        } catch (error) {
            console.error('Error processing transactions:', error);
        } finally {
            setIsLoading(false);
        }
    }, [providedTransactions]);

    const [searchMatchedIds, setSearchMatchedIds] = useState<Set<string>>(
        new Set(),
    );
    const [isSearching, setIsSearching] = useState(false);

    useEffect(() => {
        if (!filters.searchText) {
            setSearchMatchedIds(new Set());
            setIsSearching(false);
            return;
        }

        setIsSearching(true);
        const searchLower = filters.searchText.toLowerCase();
        const matchedIds = new Set<string>();

        for (const tx of transactions) {
            const description = (tx.description ?? '').toLowerCase();
            const notes = (tx.notes ?? '').toLowerCase();

            if (
                description.includes(searchLower) ||
                notes.includes(searchLower)
            ) {
                matchedIds.add(tx.id);
            }
        }

        setSearchMatchedIds(matchedIds);
        setIsSearching(false);
    }, [filters.searchText, transactions]);

    const filteredTransactions = useMemo(
        () =>
            transactions.filter((transaction) =>
                matchesFilters(
                    transaction,
                    filters,
                    accountId,
                    searchMatchedIds,
                ),
            ),
        [transactions, filters, accountId, searchMatchedIds],
    );

    const sortedTransactions = useMemo(
        () => sortRows(filteredTransactions, sorting),
        [filteredTransactions, sorting],
    );

    const displayedTransactions = useMemo(
        () => sortedTransactions.slice(0, displayedCount),
        [sortedTransactions, displayedCount],
    );

    // Over every row the filters keep, not only the drawn slice, so a day cut
    // by "load more" still says what it holds in full.
    const daySummaryOf = useInMemoryDaySummaries(filteredTransactions);

    const loadMore = useCallback(() => {
        if (displayedCount < sortedTransactions.length && !isLoadingMore) {
            setIsLoadingMore(true);
            requestAnimationFrame(() => {
                setDisplayedCount((prev) =>
                    Math.min(prev + pageSize, sortedTransactions.length),
                );
                requestAnimationFrame(() => {
                    setIsLoadingMore(false);
                });
            });
        }
    }, [displayedCount, sortedTransactions.length, isLoadingMore, pageSize]);

    useEffect(() => {
        setDisplayedCount(pageSize);
    }, [filters, sorting, pageSize]);

    return {
        rows: displayedTransactions,
        heldRows: transactions,
        setRows: setTransactions,
        isLoading: isLoading || isSearching,
        filters,
        onFiltersChange: setFilters,
        sorting,
        onSortingChange: setSorting,
        manualSorting: false,
        daySummaryOf,
        pagination: {
            displayedCount,
            total: sortedTransactions.length,
            rowCountLabel: __('transactions total'),
            hasMore: displayedCount < sortedTransactions.length,
            isLoadingMore,
            loadMore,
            loadsOnScroll: false,
        },
        reload: reloadPage,
        queryKey: 0,
    };
}
