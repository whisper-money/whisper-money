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
import { isWithinInterval, parseISO } from 'date-fns';
import { useCallback, useEffect, useMemo, useState } from 'react';

/** The rows as the table edits them: notes never undefined, label ids set. */
function toServerRows(
    transactions: Transaction[] | undefined,
): ServerTransaction[] {
    return (transactions ?? []).map(
        (transaction) =>
            ({
                ...transaction,
                notes: transaction.notes || null,
                label_ids:
                    transaction.label_ids ??
                    (transaction as Partial<ServerTransaction>).labels?.map(
                        (label) => label.id,
                    ) ??
                    [],
            }) as ServerTransaction,
    );
}

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

/** The ids of the rows whose description or notes hold the search text. */
function searchMatches(
    transactions: ServerTransaction[],
    searchText: string,
): Set<string> {
    const searchLower = searchText.toLowerCase();
    const matchedIds = new Set<string>();

    if (!searchLower) {
        return matchedIds;
    }

    for (const tx of transactions) {
        const description = (tx.description ?? '').toLowerCase();
        const notes = (tx.notes ?? '').toLowerCase();

        if (description.includes(searchLower) || notes.includes(searchLower)) {
            matchedIds.add(tx.id);
        }
    }

    return matchedIds;
}

/**
 * Newest first, the order the server lists them in: by date, then by id so
 * rows of one day keep one stable order.
 */
function compareNewestFirst(
    a: ServerTransaction,
    b: ServerTransaction,
): number {
    const byDate =
        parseISO(b.transaction_date).getTime() -
        parseISO(a.transaction_date).getTime();

    if (byDate !== 0) {
        return byDate;
    }

    // By code unit, not locale: lowercase hex uuids then compare as the
    // database orders them.
    if (a.id === b.id) {
        return 0;
    }

    return a.id < b.id ? 1 : -1;
}

/**
 * A transactions table over rows the page already holds: it filters them in
 * the browser, orders them newest first, draws them a page at a time, and
 * counts each day over every row the filters keep.
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
    const [transactions, setTransactions] = useState<ServerTransaction[]>(() =>
        toServerRows(providedTransactions),
    );
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

    // The page hands new rows over after a reload or a period change; the
    // first ones are already in the state above, so no frame draws it empty.
    const [handedRows, setHandedRows] = useState(providedTransactions);

    if (providedTransactions !== handedRows) {
        setHandedRows(providedTransactions);
        setTransactions(toServerRows(providedTransactions));
    }

    const searchMatchedIds = useMemo(
        () => searchMatches(transactions, filters.searchText),
        [filters.searchText, transactions],
    );

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

    const orderedTransactions = useMemo(
        () => [...filteredTransactions].sort(compareNewestFirst),
        [filteredTransactions],
    );

    const displayedTransactions = useMemo(
        () => orderedTransactions.slice(0, displayedCount),
        [orderedTransactions, displayedCount],
    );

    // Over every row the filters keep, not only the drawn slice, so a day cut
    // by "load more" still says what it holds in full.
    const daySummaryOf = useInMemoryDaySummaries(filteredTransactions);

    const loadMore = useCallback(() => {
        if (displayedCount < orderedTransactions.length && !isLoadingMore) {
            setIsLoadingMore(true);
            requestAnimationFrame(() => {
                setDisplayedCount((prev) =>
                    Math.min(prev + pageSize, orderedTransactions.length),
                );
                requestAnimationFrame(() => {
                    setIsLoadingMore(false);
                });
            });
        }
    }, [displayedCount, orderedTransactions.length, isLoadingMore, pageSize]);

    useEffect(() => {
        setDisplayedCount(pageSize);
    }, [filters, pageSize]);

    const countMatching = useCallback(
        async (next: Filters) => {
            const matchedIds = searchMatches(transactions, next.searchText);

            return transactions.filter((transaction) =>
                matchesFilters(transaction, next, accountId, matchedIds),
            ).length;
        },
        [transactions, accountId],
    );

    return {
        rows: displayedTransactions,
        heldRows: transactions,
        setRows: setTransactions,
        isLoading: providedTransactions === undefined,
        filters,
        onFiltersChange: setFilters,
        daySummaryOf,
        pagination: {
            displayedCount,
            total: orderedTransactions.length,
            rowCountLabel: __('transactions total'),
            hasMore: displayedCount < orderedTransactions.length,
            isLoadingMore,
            loadMore,
            loadsOnScroll: false,
        },
        reload: reloadPage,
        countMatching,
        queryKey: 0,
    };
}
