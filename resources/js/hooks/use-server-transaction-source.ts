import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { useServerDaySummaries } from '@/hooks/use-day-summaries';
import {
    cashflowBlockFromParam,
    withoutDatelessCashflowBlock,
} from '@/lib/cashflow-block';
import {
    isCursorPaginatedResponse,
    type CursorPaginatedResponse,
} from '@/lib/cursor-pagination';
import { type ServerDayTotal } from '@/lib/transaction-day-groups';
import { serializeFilters } from '@/lib/transaction-filter-serialization';
import {
    type TransactionFilters as Filters,
    type ServerTransaction,
} from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { type SortingState } from '@tanstack/react-table';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** The filters and sort the server applied to the page it served. */
export interface AppliedFilters {
    date_from: string | null;
    date_to: string | null;
    amount_min: number | null;
    amount_max: number | null;
    category_ids: string[];
    account_ids: string[];
    label_ids: string[];
    creditor_name: string;
    debtor_name: string;
    category_source: string | null;
    search: string;
    cashflow_block: string | null;
    sort: string;
}

const DEFAULT_SORT = '-transaction_date';

function serverToClientFilters(applied: AppliedFilters): Filters {
    return {
        dateFrom: applied.date_from ? toLocalDate(applied.date_from) : null,
        dateTo: applied.date_to ? toLocalDate(applied.date_to) : null,
        amountMin: applied.amount_min,
        amountMax: applied.amount_max,
        categoryIds: applied.category_ids,
        accountIds: applied.account_ids,
        labelIds: applied.label_ids,
        creditorName: applied.creditor_name,
        debtorName: applied.debtor_name,
        searchText: applied.search,
        aiCategorizedOnly: applied.category_source === 'ai',
        cashflowBlock: cashflowBlockFromParam(applied.cashflow_block),
    };
}

/**
 * The filters as the server reads them, in a bulk request's body: the saved
 * filter shape, plus the AI-only toggle no saved filter keeps.
 */
function clientFiltersToBackendFilters(
    filters: Filters,
): Record<string, unknown> {
    const { cashflow_block, ...serialized } = serializeFilters(filters);

    return {
        ...serialized,
        ...(filters.aiCategorizedOnly ? { category_source: 'ai' } : {}),
        ...(cashflow_block ? { cashflow_block } : {}),
    };
}

/** The same filters, and the sort, as the page's URL query carries them. */
export function clientFiltersToQueryParams(
    filters: Filters,
    sort: string,
): Record<string, string> {
    const params = Object.fromEntries(
        Object.entries(clientFiltersToBackendFilters(filters)).map(
            ([key, value]) => [
                key,
                Array.isArray(value) ? value.join(',') : String(value),
            ],
        ),
    );

    if (sort !== DEFAULT_SORT) {
        params.sort = sort;
    }

    return params;
}

function onlySearchChanged(next: Filters, current: Filters): boolean {
    return (
        next.searchText !== current.searchText &&
        next.dateFrom === current.dateFrom &&
        next.dateTo === current.dateTo &&
        next.amountMin === current.amountMin &&
        next.amountMax === current.amountMax &&
        JSON.stringify(next.categoryIds) ===
            JSON.stringify(current.categoryIds) &&
        JSON.stringify(next.accountIds) ===
            JSON.stringify(current.accountIds) &&
        JSON.stringify(next.labelIds) === JSON.stringify(current.labelIds) &&
        next.creditorName === current.creditorName &&
        next.debtorName === current.debtorName
    );
}

/**
 * Everything the table reads that the payload does not carry ready to use: a
 * date trimmed to its day, the account's bank hoisted onto the row, and labels
 * present as both the objects and the ids the columns filter on.
 */
function normalizeTransaction(tx: ServerTransaction): ServerTransaction {
    return {
        ...tx,
        transaction_date: String(tx.transaction_date).slice(0, 10),
        notes: tx.notes || null,
        bank: tx.account?.bank || undefined,
        labels: tx.labels || [],
        label_ids: tx.labels?.map((l) => l.id) || [],
    };
}

/**
 * A transactions table the server pages through: filters and sort live in the
 * URL and every change asks the server again, "load more" fetches the next
 * cursor page without touching the URL, and each day's count and total come
 * from the server, moved only by what the page changed since.
 */
export function useServerTransactionSource({
    transactions: serverTransactions,
    dayTotals: initialDayTotals,
    appliedFilters,
}: {
    transactions: CursorPaginatedResponse<ServerTransaction>;
    dayTotals: Record<string, ServerDayTotal>;
    appliedFilters: AppliedFilters;
}): TransactionTableSource & {
    /** The current query, for an export of exactly these rows. */
    queryParams: Record<string, string>;
} {
    const [allTransactions, setAllTransactions] = useState<ServerTransaction[]>(
        () => serverTransactions.data.map(normalizeTransaction),
    );
    const [nextCursor, setNextCursor] = useState<string | null>(
        serverTransactions.next_cursor,
    );
    // The rows exactly as served, beside the server's count and total of each
    // day they touch: the headers move those figures by local edits only.
    const [served, setServed] = useState(() => ({
        transactions: allTransactions,
        dayTotals: initialDayTotals,
    }));
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [isNavigating, setIsNavigating] = useState(false);
    const [queryKey, setQueryKey] = useState(0);

    const replaceLoadedRows = useCallback((props: Record<string, unknown>) => {
        const txns = props.transactions;

        if (!isCursorPaginatedResponse<ServerTransaction>(txns)) {
            return;
        }

        const rows = txns.data.map(normalizeTransaction);
        setAllTransactions(rows);
        setNextCursor(txns.next_cursor);
        setServed({
            transactions: rows,
            dayTotals:
                (props.dayTotals as Record<string, ServerDayTotal>) ?? {},
        });
    }, []);

    // Reset is handled explicitly via onSuccess in navigateWithFilters,
    // and append is handled via onSuccess in loadMore.
    // No useEffect watching serverTransactions — avoids race conditions.

    const [filters, setFilters] = useState<Filters>(() =>
        serverToClientFilters(appliedFilters),
    );
    const [sortParam, setSortParam] = useState(
        appliedFilters.sort || DEFAULT_SORT,
    );

    useEffect(() => {
        setFilters(serverToClientFilters(appliedFilters));
        setSortParam(appliedFilters.sort || DEFAULT_SORT);
    }, [appliedFilters]);

    const sorting = useMemo<SortingState>(() => {
        const desc = sortParam.startsWith('-');
        const column = sortParam.replace(/^-/, '');
        return [{ id: column, desc }];
    }, [sortParam]);

    const daySummaryOf = useServerDaySummaries({
        dayTotals: served.dayTotals,
        served: served.transactions,
        loaded: allTransactions,
    });

    const searchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const navigateWithFilters = useCallback(
        (newFilters: Filters, newSort: string, debounceMs = 0) => {
            if (searchTimerRef.current) {
                clearTimeout(searchTimerRef.current);
            }

            const doNavigate = () => {
                const params = clientFiltersToQueryParams(newFilters, newSort);
                setIsNavigating(true);
                setQueryKey((key) => key + 1);
                router.visit(transactionsIndex({ query: params }).url, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => replaceLoadedRows(page.props),
                    onFinish: () => setIsNavigating(false),
                });
            };

            if (debounceMs > 0) {
                searchTimerRef.current = setTimeout(doNavigate, debounceMs);
            } else {
                doNavigate();
            }
        },
        [replaceLoadedRows],
    );

    const onFiltersChange = useCallback(
        (changedFilters: Filters) => {
            const newFilters = withoutDatelessCashflowBlock(changedFilters);
            setFilters(newFilters);

            // Debounce search, immediate for other filters
            navigateWithFilters(
                newFilters,
                sortParam,
                onlySearchChanged(newFilters, filters) ? 300 : 0,
            );
        },
        [filters, sortParam, navigateWithFilters],
    );

    const onSortingChange = useCallback(
        (updater: SortingState | ((prev: SortingState) => SortingState)) => {
            const newSorting =
                typeof updater === 'function' ? updater(sorting) : updater;
            if (newSorting.length === 0) {
                return;
            }
            const { id, desc } = newSorting[0];
            const newSort = desc ? `-${id}` : id;
            setSortParam(newSort);
            navigateWithFilters(filters, newSort);
        },
        [sorting, filters, navigateWithFilters],
    );

    // Refresh the transaction list from the server (resets accumulated pages)
    const reload = useCallback(() => {
        router.reload({
            only: ['transactions', 'dayTotals'],
            onSuccess: (page) => replaceLoadedRows(page.props),
        });
    }, [replaceLoadedRows]);

    // Load More with cursor pagination (fetch directly to avoid cursor in URL)
    const { component, version } = usePage();
    const loadMore = useCallback(async () => {
        if (!nextCursor || isLoadingMore) {
            return;
        }

        setIsLoadingMore(true);
        try {
            const params = clientFiltersToQueryParams(filters, sortParam);
            params.cursor = nextCursor;
            const url = transactionsIndex({ query: params }).url;

            const response = await fetch(url, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Version': version ?? '',
                    'X-Inertia-Partial-Data': 'transactions,dayTotals',
                    'X-Inertia-Partial-Component': component,
                    Accept: 'text/html, application/xhtml+xml',
                },
            });

            const json = await response.json();
            const next = json.props.transactions;

            if (!isCursorPaginatedResponse<ServerTransaction>(next)) {
                return;
            }

            const rows = next.data.map(normalizeTransaction);
            setAllTransactions((prev) => [...prev, ...rows]);
            setNextCursor(next.next_cursor);
            // A day already shown keeps the figure it came with: the page's
            // own edits are measured against that one.
            setServed((previous) => ({
                transactions: [...previous.transactions, ...rows],
                dayTotals: { ...json.props.dayTotals, ...previous.dayTotals },
            }));
        } catch (error) {
            console.error('Failed to load more transactions:', error);
        } finally {
            setIsLoadingMore(false);
        }
    }, [nextCursor, isLoadingMore, filters, sortParam, component, version]);

    const matchingFilters = useMemo(
        () => clientFiltersToBackendFilters(filters),
        [filters],
    );

    return {
        rows: allTransactions,
        heldRows: allTransactions,
        setRows: setAllTransactions,
        isLoading: isNavigating,
        filters,
        onFiltersChange,
        sorting,
        onSortingChange,
        manualSorting: true,
        daySummaryOf,
        pagination: {
            displayedCount: allTransactions.length,
            rowCountLabel: __('transactions loaded'),
            hasMore: nextCursor !== null,
            isLoadingMore,
            loadMore,
            loadsOnScroll: true,
        },
        reload,
        queryKey,
        matchingFilters,
        queryParams: clientFiltersToQueryParams(filters, sortParam),
    };
}
