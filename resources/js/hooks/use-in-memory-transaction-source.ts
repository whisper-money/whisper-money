import { useInMemoryDaySummaries } from '@/hooks/use-day-summaries';
import { withoutDatelessCashflowBlock } from '@/lib/cashflow-block';
import { reloadPage } from '@/lib/leave-page';
import {
    createTransactionCategoryFilter,
    createTransactionCategoryTypeFilter,
} from '@/lib/transaction-category-filter';
import { createRowTotalAmount } from '@/lib/transaction-day-groups';
import { FILTER_FACETS } from '@/lib/transaction-filter-facets';
import { type Category } from '@/types/category';
import {
    type DecryptedTransaction,
    type TransactionFilters as Filters,
    type Transaction,
} from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { isWithinInterval, parseISO } from 'date-fns';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Every facet but the cashflow block, which budgets decide: the page holds
 * neither those nor the rest of the period the block is read over.
 */
const IN_MEMORY_FACETS = FILTER_FACETS.filter(
    (facet) => facet !== 'cashflowBlock',
);

/** The rows as the table edits them: text ready to read, label ids set. */
function toDecryptedRows(
    transactions: Transaction[] | undefined,
): DecryptedTransaction[] {
    return (transactions ?? []).map(
        (transaction) =>
            ({
                ...transaction,
                decryptedDescription: transaction.description,
                decryptedNotes: transaction.notes || null,
                label_ids:
                    transaction.label_ids ??
                    (transaction as Partial<DecryptedTransaction>).labels?.map(
                        (label) => label.id,
                    ) ??
                    [],
            }) as DecryptedTransaction,
    );
}

function matchesFilters(
    transaction: DecryptedTransaction,
    filters: Filters,
    accountId: UUID | undefined,
    searchMatchedIds: Set<string>,
    matchesCategory: (transaction: DecryptedTransaction) => boolean,
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

    if (
        filters.amountMin !== null &&
        transaction.amount / 100 < filters.amountMin
    ) {
        return false;
    }
    if (
        filters.amountMax !== null &&
        transaction.amount / 100 > filters.amountMax
    ) {
        return false;
    }

    if (!matchesCategory(transaction)) {
        return false;
    }

    if (filters.aiCategorizedOnly && transaction.category_source !== 'ai') {
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

/**
 * The category, label and type filters, the way the server reads them: a row
 * in a wanted category or carrying a wanted label, split lines included, that
 * also has a wanted type.
 */
function categoryMatcher(
    filters: Pick<Filters, 'categoryIds' | 'categoryTypes' | 'labelIds'>,
    categories: Category[],
): (transaction: DecryptedTransaction) => boolean {
    const { categoryIds, labelIds } = filters;
    const inCategory = createTransactionCategoryFilter(categoryIds, categories);
    const ofType = createTransactionCategoryTypeFilter(
        filters.categoryTypes,
        categories,
    );

    const inCategoryOrLabel = (transaction: DecryptedTransaction) =>
        (categoryIds.length === 0 && labelIds.length === 0) ||
        (categoryIds.length > 0 && inCategory(transaction)) ||
        (transaction.label_ids ?? []).some((id) => labelIds.includes(id));

    return (transaction) =>
        inCategoryOrLabel(transaction) && ofType(transaction);
}

/** The ids of the rows whose description or notes hold the search text. */
function searchMatches(
    transactions: DecryptedTransaction[],
    searchText: string,
): Set<string> {
    const searchLower = searchText.toLowerCase();
    const matchedIds = new Set<string>();

    if (!searchLower) {
        return matchedIds;
    }

    for (const tx of transactions) {
        const description = (
            tx.decryptedDescription ??
            tx.description ??
            ''
        ).toLowerCase();
        const notes = (tx.decryptedNotes ?? tx.notes ?? '').toLowerCase();

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
export function compareNewestFirst(
    a: DecryptedTransaction,
    b: DecryptedTransaction,
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
    categories,
    accountId,
    pageSize = 25,
    reload = reloadPage,
}: {
    /** The rows, as the page received them; undefined while they load. */
    transactions?: Transaction[];
    /** What a category filter reaches, subcategories included, and each one's type. */
    categories: Category[];
    /** Keeps the list to one account, the filter for it hidden. */
    accountId?: UUID;
    pageSize?: number;
    /** Brings in what a duplicate changed; the page reloads by default. */
    reload?: () => void;
}): TransactionTableSource {
    const [transactions, setTransactions] = useState<DecryptedTransaction[]>(
        () => toDecryptedRows(providedTransactions),
    );
    const [filters, setFilters] = useState<Filters>({
        dateFrom: null,
        dateTo: null,
        amountMin: null,
        amountMax: null,
        categoryIds: [],
        categoryTypes: [],
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
        setTransactions(toDecryptedRows(providedTransactions));
    }

    const searchMatchedIds = useMemo(
        () => searchMatches(transactions, filters.searchText),
        [filters.searchText, transactions],
    );

    const matchesCategory = useMemo(
        () => categoryMatcher(filters, categories),
        [filters, categories],
    );

    const filteredTransactions = useMemo(
        () =>
            transactions.filter((transaction) =>
                matchesFilters(
                    transaction,
                    filters,
                    accountId,
                    searchMatchedIds,
                    matchesCategory,
                ),
            ),
        [transactions, filters, accountId, searchMatchedIds, matchesCategory],
    );

    const orderedTransactions = useMemo(
        () => [...filteredTransactions].sort(compareNewestFirst),
        [filteredTransactions],
    );

    const displayedTransactions = useMemo(
        () => orderedTransactions.slice(0, displayedCount),
        [orderedTransactions, displayedCount],
    );

    const rowTotalAmount = useMemo(
        () => createRowTotalAmount(filters, categories),
        [filters, categories],
    );

    // Over every row the filters keep, not only the drawn slice, so a day cut
    // by "load more" still says what it holds in full.
    const daySummaryOf = useInMemoryDaySummaries(filteredTransactions, {
        // The slice bounds the days.
        drawn: displayedTransactions,
        rowTotalAmount,
    });

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
            const matchesNextCategory = categoryMatcher(next, categories);

            return transactions.filter((transaction) =>
                matchesFilters(
                    transaction,
                    next,
                    accountId,
                    matchedIds,
                    matchesNextCategory,
                ),
            ).length;
        },
        [transactions, accountId, categories],
    );

    const onFiltersChange = useCallback(
        (next: Filters) => setFilters(withoutDatelessCashflowBlock(next)),
        [],
    );

    return {
        rows: displayedTransactions,
        heldRows: transactions,
        setRows: setTransactions,
        isLoading: providedTransactions === undefined,
        filters,
        onFiltersChange,
        facets: IN_MEMORY_FACETS,
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
        reload,
        countMatching,
        queryKey: 0,
    };
}
