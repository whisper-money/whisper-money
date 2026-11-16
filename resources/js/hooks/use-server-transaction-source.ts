import { listedCount as transactionsListedCount } from '@/actions/App/Http/Controllers/Api/TransactionController';
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
import { normalizeTransaction } from '@/lib/normalize-transaction';
import {
    createRowTotalAmount,
    mergeNextPage,
    type ServerDayTotal,
} from '@/lib/transaction-day-groups';
import { FILTER_FACETS } from '@/lib/transaction-filter-facets';
import {
    hasActiveFilters,
    serializeFilters,
} from '@/lib/transaction-filter-serialization';
import { type Category, type CategoryType } from '@/types/category';
import {
    type DecryptedTransaction,
    type TransactionFilters as Filters,
    type ServerTransaction,
} from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { type CancelToken } from '@inertiajs/core';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/** The filters the server applied to the page it served. */
export interface AppliedFilters {
    date_from: string | null;
    date_to: string | null;
    amount_min: number | null;
    amount_max: number | null;
    category_ids: string[];
    category_types: CategoryType[];
    account_ids: string[];
    label_ids: string[];
    creditor_name: string;
    debtor_name: string;
    category_source: string | null;
    search: string;
    cashflow_block: string | null;
}

function serverToClientFilters(applied: AppliedFilters): Filters {
    return {
        dateFrom: applied.date_from ? toLocalDate(applied.date_from) : null,
        dateTo: applied.date_to ? toLocalDate(applied.date_to) : null,
        amountMin: applied.amount_min,
        amountMax: applied.amount_max,
        categoryIds: applied.category_ids,
        // A page kept in the browser's history may predate the filter.
        categoryTypes: applied.category_types ?? [],
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

/** The same filters, as the page's URL query carries them. */
export function clientFiltersToQueryParams(
    filters: Filters,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(clientFiltersToBackendFilters(filters)).map(
            ([key, value]) => [
                key,
                Array.isArray(value) ? value.join(',') : String(value),
            ],
        ),
    );
}

/**
 * How many rows the server lists for a filter set: not the export's count,
 * which is per split line.
 */
async function countMatching(
    filters: Filters,
    signal: AbortSignal,
): Promise<number> {
    const { data } = await axios.get<{ count: number }>(
        transactionsListedCount.url({
            query: clientFiltersToQueryParams(filters),
        }),
        { signal },
    );

    return data.count;
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
        JSON.stringify(next.categoryTypes) ===
            JSON.stringify(current.categoryTypes) &&
        JSON.stringify(next.accountIds) ===
            JSON.stringify(current.accountIds) &&
        JSON.stringify(next.labelIds) === JSON.stringify(current.labelIds) &&
        next.creditorName === current.creditorName &&
        next.debtorName === current.debtorName
    );
}

/**
 * How long edits rest before the summary is asked again, so a run of them, or
 * a bulk action that reports row by row, costs one request.
 */
const SUMMARY_REFRESH_DEBOUNCE_MS = 500;

/**
 * A transactions table the server pages through, newest first: filters live in
 * the URL and every change asks the server again, "load more" fetches the next
 * cursor page without touching the URL, and each day's count and total come
 * from the server, moved only by what the page changed since.
 */
export function useServerTransactionSource({
    transactions: serverTransactions,
    dayTotals: initialDayTotals,
    appliedFilters,
    categories,
}: {
    transactions: CursorPaginatedResponse<ServerTransaction>;
    dayTotals: Record<string, ServerDayTotal>;
    appliedFilters: AppliedFilters;
    /** What a category filter reaches, to value a split transaction edited here. */
    categories: Category[];
}): TransactionTableSource & {
    /** The current query, for an export of exactly these rows. */
    queryParams: Record<string, string>;
    /** Asks for the filter summary again, after a local edit moved it. */
    refreshSummary: () => void;
} {
    const [allTransactions, setAllTransactions] = useState<
        DecryptedTransaction[]
    >(() => serverTransactions.data.map(normalizeTransaction));
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
    const pendingPage = useRef<AbortController | null>(null);

    // A page asked for the rows on screen has no place in any other list.
    const discardPendingPage = useCallback(() => {
        pendingPage.current?.abort();
        pendingPage.current = null;
        setIsLoadingMore(false);
    }, []);

    const replaceLoadedRows = useCallback(
        (props: Record<string, unknown>) => {
            const txns = props.transactions;

            if (!isCursorPaginatedResponse<ServerTransaction>(txns)) {
                return;
            }

            discardPendingPage();

            const rows = txns.data.map(normalizeTransaction);
            setAllTransactions(rows);
            setNextCursor(txns.next_cursor);
            setServed({
                transactions: rows,
                dayTotals:
                    (props.dayTotals as Record<string, ServerDayTotal>) ?? {},
            });
        },
        [discardPendingPage],
    );

    // Reset is handled explicitly via onSuccess in navigateWithFilters,
    // and append is handled via onSuccess in loadMore.
    // No useEffect watching serverTransactions — avoids race conditions.

    const [filters, setFilters] = useState<Filters>(() =>
        serverToClientFilters(appliedFilters),
    );

    useEffect(() => {
        setFilters(serverToClientFilters(appliedFilters));
    }, [appliedFilters]);

    const rowTotalAmount = useMemo(
        () => createRowTotalAmount(filters, categories),
        [filters, categories],
    );

    const daySummaryOf = useServerDaySummaries({
        dayTotals: served.dayTotals,
        served: served.transactions,
        loaded: allTransactions,
        rowTotalAmount,
    });

    const searchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const summaryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const summaryReloadRef = useRef<CancelToken | null>(null);
    // The visit on its way, if any: a later one supersedes it, and the earlier
    // one's onFinish must not read as the later one's.
    const navigatingRef = useRef<object | null>(null);

    // Whatever replaces the rows brings its own summary, or asks for one.
    const cancelSummaryRefresh = useCallback(() => {
        if (summaryTimerRef.current) {
            clearTimeout(summaryTimerRef.current);
            summaryTimerRef.current = null;
        }
        summaryReloadRef.current?.cancel();
        summaryReloadRef.current = null;
    }, []);

    useEffect(() => cancelSummaryRefresh, [cancelSummaryRefresh]);

    const navigateWithFilters = useCallback(
        (newFilters: Filters, debounceMs = 0) => {
            if (searchTimerRef.current) {
                clearTimeout(searchTimerRef.current);
            }

            const doNavigate = () => {
                const params = clientFiltersToQueryParams(newFilters);
                const navigation = {};
                navigatingRef.current = navigation;
                cancelSummaryRefresh();
                setIsNavigating(true);
                setQueryKey((key) => key + 1);
                router.visit(transactionsIndex({ query: params }).url, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => replaceLoadedRows(page.props),
                    onFinish: () => {
                        if (navigatingRef.current === navigation) {
                            navigatingRef.current = null;
                        }
                        setIsNavigating(false);
                    },
                });
            };

            if (debounceMs > 0) {
                searchTimerRef.current = setTimeout(doNavigate, debounceMs);
            } else {
                doNavigate();
            }
        },
        [replaceLoadedRows, cancelSummaryRefresh],
    );

    const onFiltersChange = useCallback(
        (changedFilters: Filters) => {
            const newFilters = withoutDatelessCashflowBlock(changedFilters);
            setFilters(newFilters);
            discardPendingPage();
            cancelSummaryRefresh();

            // Debounce search, immediate for other filters
            navigateWithFilters(
                newFilters,
                onlySearchChanged(newFilters, filters) ? 300 : 0,
            );
        },
        [
            filters,
            navigateWithFilters,
            discardPendingPage,
            cancelSummaryRefresh,
        ],
    );

    // Refresh the transaction list from the server (resets accumulated pages),
    // and the summary of what the filters match, which an edit can move.
    const reload = useCallback(() => {
        cancelSummaryRefresh();
        router.reload({
            only: ['transactions', 'dayTotals', 'filterSummary'],
            onSuccess: (page) => replaceLoadedRows(page.props),
        });
    }, [replaceLoadedRows, cancelSummaryRefresh]);

    // Async, so it never cancels a visit the user started meanwhile. Without
    // a filter the server sends no summary, so there is nothing to ask for.
    // Nor during a visit: a reload targets the URL still in the bar, so its
    // answer would land an old summary and URL on the new list, and the visit
    // brings its own summary anyway.
    const refreshSummary = useCallback(() => {
        cancelSummaryRefresh();

        if (navigatingRef.current || !hasActiveFilters(filters)) {
            return;
        }

        summaryTimerRef.current = setTimeout(() => {
            summaryTimerRef.current = null;
            router.reload({
                only: ['filterSummary'],
                async: true,
                onCancelToken: (token) => {
                    summaryReloadRef.current = token;
                },
                onFinish: () => {
                    summaryReloadRef.current = null;
                },
            });
        }, SUMMARY_REFRESH_DEBOUNCE_MS);
    }, [filters, cancelSummaryRefresh]);

    // Load More with cursor pagination (fetch directly to avoid cursor in URL)
    const { component, version } = usePage();
    const loadMore = useCallback(async () => {
        if (!nextCursor || isLoadingMore) {
            return;
        }

        const page = new AbortController();
        pendingPage.current = page;

        setIsLoadingMore(true);
        try {
            const params = clientFiltersToQueryParams(filters);
            params.cursor = nextCursor;
            const url = transactionsIndex({ query: params }).url;

            const response = await fetch(url, {
                signal: page.signal,
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

            if (
                page.signal.aborted ||
                !isCursorPaginatedResponse<ServerTransaction>(next)
            ) {
                return;
            }

            const rows = next.data.map(normalizeTransaction);
            setAllTransactions((prev) => [...prev, ...rows]);
            setNextCursor(next.next_cursor);
            setServed((previous) =>
                mergeNextPage(
                    previous,
                    { transactions: rows, dayTotals: json.props.dayTotals },
                    allTransactions,
                ),
            );
        } catch (error) {
            if (!page.signal.aborted) {
                console.error('Failed to load more transactions:', error);
            }
        } finally {
            if (pendingPage.current === page) {
                pendingPage.current = null;
                setIsLoadingMore(false);
            }
        }
    }, [
        nextCursor,
        isLoadingMore,
        filters,
        component,
        version,
        allTransactions,
    ]);

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
        facets: FILTER_FACETS,
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
        refreshRows: reload,
        refreshSummary,
        countMatching,
        queryKey,
        matchingFilters,
        queryParams: clientFiltersToQueryParams(filters),
    };
}
