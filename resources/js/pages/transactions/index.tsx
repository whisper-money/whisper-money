import { bulkUpdate as bulkUpdateTransactions } from '@/actions/App/Http/Controllers/TransactionController';
import { useServerDaySummaries } from '@/hooks/use-day-summaries';
import { useLocale } from '@/hooks/use-locale';
import { useMobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { useBalanceOwningAccountIds } from '@/hooks/use-owns-balance-updates';
import { usePollJobStatus } from '@/hooks/use-poll-job-status';
import { useStoredColumnVisibility } from '@/hooks/use-stored-column-visibility';
import { useTransactionDayGrouping } from '@/hooks/use-transaction-day-grouping';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import {
    getCoreRowModel,
    Row,
    SortingState,
    useReactTable,
} from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import axios from 'axios';
import { format } from 'date-fns';
import { ChevronRight, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import HeadingSmall from '@/components/heading-small';
import { BulkActionsBar } from '@/components/transactions/bulk-actions-bar';
import { ResponsiveTransactionTable } from '@/components/transactions/responsive-transaction-table';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { createTransactionColumns } from '@/components/transactions/transaction-columns';
import { TransactionExportButton } from '@/components/transactions/transaction-export-button';
import { TransactionFilters as TransactionFiltersComponent } from '@/components/transactions/transaction-filters';
import { TransactionListSkeleton } from '@/components/transactions/transaction-list';
import { TransactionRowDialogs } from '@/components/transactions/transaction-row-dialogs';
import { TransactionTableRow } from '@/components/transactions/transaction-table-row';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataTablePagination } from '@/components/ui/data-table-pagination';
import { DataTableViewOptions } from '@/components/ui/data-table-view-options';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import {
    cashflowBlockFromParam,
    cashflowBlockParam,
    withoutDatelessCashflowBlock,
} from '@/lib/cashflow-block';
import {
    isCursorPaginatedResponse,
    type CursorPaginatedResponse,
} from '@/lib/cursor-pagination';
import { consoleDebug } from '@/lib/debug';
import { isNewSince } from '@/lib/new-transactions';
import { applyBulkLabels } from '@/lib/transaction-bulk-labels';
import { type ServerDayTotal } from '@/lib/transaction-day-groups';
import { getBulkDeleteConfirmationText } from '@/lib/transaction-delete-confirmation';
import { describeActiveFilters } from '@/lib/transaction-filter-chips';
import { reEvaluateTransactionRules } from '@/lib/transaction-re-evaluation';
import { isSplitPart } from '@/lib/transaction-splits';
import { status as categorizationStatus } from '@/routes/ai/categorization';
import {
    dismiss as dismissConsent,
    store as storeConsent,
} from '@/routes/ai/consent';
import { transactionSyncService } from '@/services/transaction-sync';
import { type BreadcrumbItem, type SharedData } from '@/types';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import {
    type AiConsentResponse,
    type CategorizationProgress,
} from '@/types/categorization';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type TransactionFilters as Filters,
    type ServerTransaction,
} from '@/types/transaction';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Transactions',
        href: transactionsIndex.url(),
    },
];

interface AppliedFilters {
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

interface Props {
    transactions: CursorPaginatedResponse<ServerTransaction>;
    dayTotals: Record<string, ServerDayTotal>;
    appliedFilters: AppliedFilters;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules: AutomationRule[];
    hasAiConsent: boolean;
    aiConsentPromptDismissed: boolean;
    lastVisitAt: string | null;
}

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

function clientFiltersToQueryParams(
    filters: Filters,
    sort: string,
): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.dateFrom) {
        params.date_from = format(filters.dateFrom, 'yyyy-MM-dd');
    }
    if (filters.dateTo) {
        params.date_to = format(filters.dateTo, 'yyyy-MM-dd');
    }
    if (filters.amountMin !== null) {
        params.amount_min = filters.amountMin.toString();
    }
    if (filters.amountMax !== null) {
        params.amount_max = filters.amountMax.toString();
    }
    if (filters.categoryIds.length > 0) {
        params.category_ids = filters.categoryIds.join(',');
    }
    if (filters.accountIds.length > 0) {
        params.account_ids = filters.accountIds.join(',');
    }
    if (filters.labelIds.length > 0) {
        params.label_ids = filters.labelIds.join(',');
    }
    if (filters.creditorName) {
        params.creditor_name = filters.creditorName;
    }
    if (filters.debtorName) {
        params.debtor_name = filters.debtorName;
    }
    if (filters.searchText) {
        params.search = filters.searchText;
    }
    if (filters.aiCategorizedOnly) {
        params.category_source = 'ai';
    }
    const cashflowBlock = cashflowBlockParam(filters);
    if (cashflowBlock) {
        params.cashflow_block = cashflowBlock;
    }
    if (sort !== '-transaction_date') {
        params.sort = sort;
    }

    return params;
}

function clientFiltersToBackendFilters(
    filters: Filters,
): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    if (filters.dateFrom) {
        result.date_from = format(filters.dateFrom, 'yyyy-MM-dd');
    }
    if (filters.dateTo) {
        result.date_to = format(filters.dateTo, 'yyyy-MM-dd');
    }
    if (filters.amountMin !== null && filters.amountMin !== undefined) {
        result.amount_min = filters.amountMin;
    }
    if (filters.amountMax !== null && filters.amountMax !== undefined) {
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
    if (filters.aiCategorizedOnly) {
        result.category_source = 'ai';
    }
    const cashflowBlock = cashflowBlockParam(filters);
    if (cashflowBlock) {
        result.cashflow_block = cashflowBlock;
    }

    return result;
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

export default function Transactions({
    transactions: serverTransactions,
    dayTotals: initialDayTotals,
    appliedFilters,
    categories,
    accounts,
    banks,
    labels: initialLabels,
    automationRules,
    hasAiConsent,
    aiConsentPromptDismissed,
    lastVisitAt,
}: Props) {
    const locale = useLocale();
    const { auth } = usePage<SharedData>().props;
    const [aiConsentResolved, setAiConsentResolved] = useState(false);
    const [aiConsentSaving, setAiConsentSaving] = useState(false);
    // Never on a shared account: consent means nothing when everybody holds the
    // credentials, and the route behind the banner is blocked, so offering it
    // would only hand a journalist a broken button mid-demo.
    const showAiConsentBanner =
        auth.hasProPlan &&
        !auth.isSharedAccount &&
        !hasAiConsent &&
        !aiConsentPromptDismissed &&
        !aiConsentResolved;
    const [categorizingIds, setCategorizingIds] = useState<Set<string>>(
        () => new Set(),
    );
    const categorizationToastRef = useRef<string | number | undefined>(
        undefined,
    );
    const { start: startCategorizationPoll } = usePollJobStatus();

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
    // and append is handled via onSuccess in handleLoadMore.
    // No useEffect watching serverTransactions — avoids race conditions.

    // Filter state from server-applied filters
    const [filters, setFilters] = useState<Filters>(() =>
        serverToClientFilters(appliedFilters),
    );
    const [sortParam, setSortParam] = useState(
        appliedFilters.sort || '-transaction_date',
    );

    // Frozen at mount so per-row "new" marks stay stable for the whole visit.
    // The marker is server-side (per user), so it's shared across devices; the
    // controller advances it on load using the newest created_at it served.
    const [lastVisitAtMount] = useState<string | null>(() => lastVisitAt);

    // Sync filter state when appliedFilters prop changes
    useEffect(() => {
        setFilters(serverToClientFilters(appliedFilters));
        setSortParam(appliedFilters.sort || '-transaction_date');
    }, [appliedFilters]);

    // Derive sorting state for TanStack Table from sort param
    const sorting = useMemo<SortingState>(() => {
        const desc = sortParam.startsWith('-');
        const column = sortParam.replace(/^-/, '');
        return [{ id: column, desc }];
    }, [sortParam]);

    const [columnVisibility, setColumnVisibility] = useStoredColumnVisibility();
    const [rowSelection, setRowSelection] = useState({});
    const editing = useTransactionRowEditing({
        initialLabels,
        setRows: setAllTransactions,
    });
    const {
        labels,
        handleLabelCreated,
        rowActions,
        updateTransaction,
        handleCategorized,
        deleteTransaction,
        setDeleteTransaction,
        isReEvaluating,
        setIsReEvaluating,
    } = editing;
    const [isBulkDeleteMode, setIsBulkDeleteMode] = useState(false);
    const [updateBalanceOnDelete, setUpdateBalanceOnDelete] = useState(true);
    const [bulkDeleteConfirmation, setBulkDeleteConfirmation] = useState('');
    const [isDeleting, setIsDeleting] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [isBulkUpdating, setIsBulkUpdating] = useState(false);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [isSelectingAll, setIsSelectingAll] = useState(false);
    const [isNavigating, setIsNavigating] = useState(false);

    const selectOnlyVisible = useCallback(() => setIsSelectingAll(false), []);

    // Unticking a row or a whole day means the user is narrowing down what
    // they see, so a bulk action must no longer reach every matching row.
    const tickedCount = Object.keys(rowSelection).length;
    const [previousTickedCount, setPreviousTickedCount] = useState(tickedCount);

    if (tickedCount !== previousTickedCount) {
        setPreviousTickedCount(tickedCount);

        if (tickedCount < previousTickedCount) {
            setIsSelectingAll(false);
        }
    }

    const selectionMode = useMobileSelectionMode({
        rowSelection,
        setRowSelection,
        rows: allTransactions,
        onClear: selectOnlyVisible,
    });

    const { isGroupedByDay, tableColumnVisibility, onColumnVisibilityChange } =
        useTransactionDayGrouping({
            sorting,
            columnVisibility,
            setColumnVisibility,
        });

    const daySummaryOf = useServerDaySummaries({
        dayTotals: served.dayTotals,
        served: served.transactions,
        loaded: allTransactions,
    });

    // Debounce timer ref for search
    const searchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Navigate to server with new filters/sort
    const navigateWithFilters = useCallback(
        (newFilters: Filters, newSort: string, debounceMs = 0) => {
            if (searchTimerRef.current) {
                clearTimeout(searchTimerRef.current);
            }

            const doNavigate = () => {
                const params = clientFiltersToQueryParams(newFilters, newSort);
                setIsNavigating(true);
                setRowSelection({});
                setIsSelectingAll(false);
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

    // Handle filter changes from the filter component
    const handleFiltersChange = useCallback(
        (changedFilters: Filters) => {
            const newFilters = withoutDatelessCashflowBlock(changedFilters);
            setFilters(newFilters);

            // Debounce search, immediate for other filters
            const isSearchChange = newFilters.searchText !== filters.searchText;
            const onlySearchChanged =
                isSearchChange &&
                newFilters.dateFrom === filters.dateFrom &&
                newFilters.dateTo === filters.dateTo &&
                newFilters.amountMin === filters.amountMin &&
                newFilters.amountMax === filters.amountMax &&
                JSON.stringify(newFilters.categoryIds) ===
                    JSON.stringify(filters.categoryIds) &&
                JSON.stringify(newFilters.accountIds) ===
                    JSON.stringify(filters.accountIds) &&
                JSON.stringify(newFilters.labelIds) ===
                    JSON.stringify(filters.labelIds) &&
                newFilters.creditorName === filters.creditorName &&
                newFilters.debtorName === filters.debtorName;

            navigateWithFilters(
                newFilters,
                sortParam,
                onlySearchChanged ? 300 : 0,
            );
        },
        [filters, sortParam, navigateWithFilters],
    );

    // Handle sort changes from table column headers
    const handleSortingChange = useCallback(
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
    const refreshTransactions = useCallback(() => {
        router.reload({
            only: ['transactions', 'dayTotals'],
            onSuccess: (page) => replaceLoadedRows(page.props),
        });
    }, [replaceLoadedRows]);

    // Clear the spinner for any row AI has finished categorizing.
    useEffect(() => {
        setCategorizingIds((previous) => {
            if (previous.size === 0) {
                return previous;
            }

            let changed = false;
            const next = new Set(previous);
            for (const transaction of allTransactions) {
                if (next.has(transaction.id) && transaction.category_id) {
                    next.delete(transaction.id);
                    changed = true;
                }
            }

            return changed ? next : previous;
        });
    }, [allTransactions]);

    const pollCategorizationStatus = useCallback(
        (jobId: string, fallbackTotal: number) => {
            let pendingTicks = 0;

            const dismiss = () => {
                setCategorizingIds(new Set());
                toast.dismiss(categorizationToastRef.current);
                categorizationToastRef.current = undefined;
            };

            startCategorizationPoll(async () => {
                let data;
                try {
                    ({ data } = await axios.get<CategorizationProgress>(
                        categorizationStatus(jobId).url,
                    ));
                } catch {
                    dismiss();
                    return 'stop';
                }

                // The job never started (e.g. no queue worker running) — give up
                // instead of polling forever.
                if (data.status === 'pending' && ++pendingTicks > 15) {
                    dismiss();
                    return 'stop';
                }

                const total = data.total || fallbackTotal;
                categorizationToastRef.current = toast.loading(
                    __('Categorizing :processed of :total transactions…', {
                        processed: data.processed,
                        total,
                    }),
                    { id: categorizationToastRef.current },
                );

                // Pull whatever AI has applied so far into the visible rows.
                if (data.status === 'processing' || data.status === 'done') {
                    refreshTransactions();
                }

                if (data.status === 'done' || data.status === 'failed') {
                    setCategorizingIds(new Set());
                    if (data.status === 'failed') {
                        toast.error(
                            __('AI categorization failed. Please try again.'),
                            { id: categorizationToastRef.current },
                        );
                    } else if (data.applied > 0) {
                        toast.success(
                            __('AI categorized :count transactions', {
                                count: data.applied,
                            }),
                            { id: categorizationToastRef.current },
                        );
                    } else {
                        toast.dismiss(categorizationToastRef.current);
                    }
                    categorizationToastRef.current = undefined;
                    return 'stop';
                }

                return 'continue';
            }, 2000);
        },
        [startCategorizationPoll, refreshTransactions],
    );

    const handleEnableAi = useCallback(async () => {
        setAiConsentSaving(true);
        try {
            const { data } = await axios.post<AiConsentResponse>(
                storeConsent.url(),
            );
            setAiConsentResolved(true);
            toast.success(
                __(
                    'AI enabled. You can change this anytime in Settings > Manage Plan.',
                ),
            );

            if (data.categorization) {
                // Spin every currently-visible uncategorized row while the
                // backfill runs; the prune effect clears each as it lands.
                setCategorizingIds(
                    new Set(
                        allTransactions
                            .filter((transaction) => !transaction.category_id)
                            .map((transaction) => transaction.id),
                    ),
                );
                categorizationToastRef.current = toast.loading(
                    __('Categorizing :processed of :total transactions…', {
                        processed: 0,
                        total: data.categorization.total,
                    }),
                );
                pollCategorizationStatus(
                    data.categorization.job_id,
                    data.categorization.total,
                );
            }
        } catch {
            toast.error(__('Something went wrong.'));
        } finally {
            setAiConsentSaving(false);
        }
    }, [allTransactions, pollCategorizationStatus]);

    const handleDismissAiConsent = useCallback(() => {
        setAiConsentResolved(true);
        axios.post(dismissConsent.url()).catch(() => {
            // Best-effort: the banner is already hidden for this session.
        });
        toast(
            __(
                'AI not enabled. You can turn it on anytime in Settings > Manage Plan.',
            ),
        );
    }, []);

    // Load More with cursor pagination (fetch directly to avoid cursor in URL)
    const { component, version } = usePage();
    const handleLoadMore = useCallback(async () => {
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

    // Auto-load more when the sentinel becomes visible
    const loadMoreRef = useRef<HTMLDivElement | null>(null);
    useEffect(() => {
        const el = loadMoreRef.current;
        if (!el || !nextCursor) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    handleLoadMore();
                }
            },
            { rootMargin: '200px' },
        );

        observer.observe(el);
        return () => observer.disconnect();
    }, [nextCursor, handleLoadMore]);

    async function handleBulkReEvaluateRules() {
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');

        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsReEvaluating(true);

        try {
            const outcome = await reEvaluateTransactionRules(
                isSelectingAll
                    ? { filters: clientFiltersToBackendFilters(filters) }
                    : { transaction_ids: selectedIds },
            );

            // A queued job will still run, so the selection is handed off
            // either way; keeping it would invite a duplicate job.
            if (outcome !== 'failed') {
                setRowSelection({});
                setIsSelectingAll(false);
            }

            if (outcome === 'done') {
                refreshTransactions();
            }
        } finally {
            setIsReEvaluating(false);
        }
    }

    const columns = useMemo(
        () =>
            createTransactionColumns({
                categories,
                accounts,
                banks,
                labels,
                locale,
                ...rowActions,
                onUpdate: updateTransaction,
                onCategorized: handleCategorized,
                isDateHidden: tableColumnVisibility.transaction_date === false,
                isGroupedByDay,
                categorizingIds,
            }),
        [
            accounts,
            banks,
            categories,
            labels,
            locale,
            rowActions,
            updateTransaction,
            handleCategorized,
            tableColumnVisibility,
            isGroupedByDay,
            categorizingIds,
        ],
    );

    const table = useReactTable({
        data: allTransactions,
        columns,
        manualSorting: true,
        onSortingChange: handleSortingChange,
        getRowId: (row) => row.id.toString(),
        getCoreRowModel: getCoreRowModel(),
        onColumnVisibilityChange,
        onRowSelectionChange: setRowSelection,
        enableRowSelection: true,
        state: {
            sorting,
            columnVisibility: tableColumnVisibility,
            rowSelection,
        },
    });

    async function handleDelete() {
        if (!deleteTransaction) {
            return;
        }

        setIsDeleting(true);
        try {
            await transactionSyncService.delete(deleteTransaction.id, {
                updateBalance:
                    updateBalanceOnDelete &&
                    manualAccountIds.has(deleteTransaction.account_id),
            });
            setAllTransactions((previous) =>
                previous.filter(
                    (transaction) => transaction.id !== deleteTransaction.id,
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transaction:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    async function handleBulkCategoryChange(categoryId: number | null) {
        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            if (isSelectingAll) {
                const toastId = toast.loading(__('Updating transactions...'));
                const response = await axios.patch<{ count: number }>(
                    bulkUpdateTransactions.url(),
                    {
                        filters: clientFiltersToBackendFilters(filters),
                        category_id: categoryId,
                    },
                );
                toast.dismiss(toastId);
                toast.success(
                    __(`Updated :count transactions`, {
                        count: response.data.count,
                    }),
                );
                setRowSelection({});
                setIsSelectingAll(false);
                refreshTransactions();
            } else {
                const categoriesMap = new Map(
                    categories.map((category) => [category.id, category]),
                );
                const selectedCategory = categoryId
                    ? categoriesMap.get(categoryId) || null
                    : null;

                await transactionSyncService.updateMany(selectedIds, {
                    category_id: categoryId,
                });

                setAllTransactions((previous) =>
                    previous.map((transaction) => {
                        if (selectedIds.includes(transaction.id.toString())) {
                            return {
                                ...transaction,
                                category_id: categoryId,
                                category: selectedCategory,
                            };
                        }
                        return transaction;
                    }),
                );

                toast.success(
                    __(`Updated :count transactions`, {
                        count: selectedIds.length,
                    }),
                );

                setRowSelection({});
            }
        } catch (error) {
            console.error('Failed to update transactions:', error);
            toast.error(__('Failed to update transactions'));
        } finally {
            setIsBulkUpdating(false);
        }
    }

    const selectedIds = useMemo(
        () => Object.keys(rowSelection),
        [rowSelection],
    );

    const selectedCount = useMemo(() => selectedIds.length, [selectedIds]);

    const hasTransactionalAccounts = useMemo(
        () => filterTransactionalAccounts(accounts).length > 0,
        [accounts],
    );

    const manualAccountIds = useBalanceOwningAccountIds(accounts);

    const bulkDeleteTouchesManualAccount = useMemo(
        () =>
            isSelectingAll
                ? manualAccountIds.size > 0
                : allTransactions.some(
                      (transaction) =>
                          selectedIds.includes(transaction.id) &&
                          manualAccountIds.has(transaction.account_id),
                  ),
        [isSelectingAll, manualAccountIds, allTransactions, selectedIds],
    );

    const bulkDeleteConfirmationText = useMemo(
        () => getBulkDeleteConfirmationText(selectedCount),
        [selectedCount],
    );

    function handleBulkDeleteClick() {
        if (selectedIds.length === 0) {
            return;
        }

        // One part of a split cannot go on its own, and refusing the whole
        // batch up front beats failing halfway through it.
        const selectedSplitParts = allTransactions.filter(
            (transaction) =>
                selectedIds.includes(transaction.id.toString()) &&
                isSplitPart(transaction),
        );

        if (selectedSplitParts.length > 0) {
            toast.error(
                __(
                    ':count of the selected transactions are splits. Merge them back before deleting them.',
                    { count: selectedSplitParts.length },
                ),
            );

            return;
        }

        const firstSelectedTransaction = allTransactions.find(
            (t) => t.id.toString() === selectedIds[0],
        );

        if (firstSelectedTransaction) {
            setIsBulkDeleteMode(true);
            setDeleteTransaction(firstSelectedTransaction);
        }
    }

    async function handleBulkDelete() {
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkDeleting(true);
        try {
            await transactionSyncService.deleteMany(selectedIds, {
                updateBalance:
                    updateBalanceOnDelete && bulkDeleteTouchesManualAccount,
            });
            setAllTransactions((previous) =>
                previous.filter(
                    (transaction) => !selectedIds.includes(transaction.id),
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setBulkDeleteConfirmation('');
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transactions:', error);
        } finally {
            setIsBulkDeleting(false);
        }
    }

    async function handleBulkLabelsChange(labelIds: string[]) {
        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            if (isSelectingAll) {
                const toastId = toast.loading(__('Updating transactions...'));
                const response = await axios.patch<{ count: number }>(
                    bulkUpdateTransactions.url(),
                    {
                        filters: clientFiltersToBackendFilters(filters),
                        label_ids: labelIds,
                    },
                );
                toast.dismiss(toastId);
                toast.success(
                    __(`Updated :count transactions`, {
                        count: response.data.count,
                    }),
                );
                setRowSelection({});
                setIsSelectingAll(false);
                refreshTransactions();
            } else {
                await transactionSyncService.updateMany(selectedIds, {
                    label_ids: labelIds,
                });

                setAllTransactions((previous) =>
                    applyBulkLabels(previous, selectedIds, labelIds, labels),
                );

                toast.success(
                    __(`Updated :count transactions`, {
                        count: selectedIds.length,
                    }),
                );

                setRowSelection({});
            }
        } catch (error) {
            console.error('Failed to update transactions with labels:', error);
            toast.error(__('Failed to update transactions with labels'));
        } finally {
            setIsBulkUpdating(false);
        }
    }

    function handleSelectAll() {
        setIsSelectingAll(true);
    }

    const renderTransactionRow = useCallback(
        (
            row: Row<ServerTransaction>,
            virtualRow: VirtualItem,
            rowVirtualizer: Virtualizer<HTMLDivElement, Element>,
        ) => {
            return (
                <TransactionTableRow
                    key={row.id}
                    row={row}
                    virtualRow={virtualRow}
                    rowVirtualizer={rowVirtualizer}
                    isNew={isNewSince(row.original, lastVisitAtMount)}
                    cellClassName="pt-2.5 pb-2"
                    {...rowActions}
                />
            );
        },
        [rowActions, lastVisitAtMount],
    );

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Transactions')} />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title={__('Transactions')}
                    description={__('View and manage your transactions')}
                />

                <div className="space-y-4">
                    <TransactionFiltersComponent
                        filters={filters}
                        onFiltersChange={handleFiltersChange}
                        categories={categories}
                        labels={labels}
                        accounts={accounts}
                        enableSavedFilters={true}
                        actions={
                            <div className="flex w-full flex-wrap items-center justify-between gap-2">
                                <TransactionActionsMenu
                                    transactions={allTransactions}
                                    hasTransactionalAccounts={
                                        hasTransactionalAccounts
                                    }
                                    onReEvaluateComplete={() => {
                                        setRowSelection({});
                                        refreshTransactions();
                                    }}
                                    filters={filters}
                                />

                                <div className="ml-auto flex items-center gap-2">
                                    <TransactionExportButton
                                        params={clientFiltersToQueryParams(
                                            filters,
                                            sortParam,
                                        )}
                                        loadedIds={allTransactions.map(
                                            (transaction) => transaction.id,
                                        )}
                                        hasMore={nextCursor !== null}
                                        filterChips={describeActiveFilters(
                                            filters,
                                            {
                                                categories,
                                                accounts,
                                                labels,
                                                locale,
                                            },
                                        )}
                                    />
                                    <DataTableViewOptions table={table} />
                                </div>
                            </div>
                        }
                    />

                    {isNavigating ? (
                        <TransactionListSkeleton />
                    ) : (
                        <>
                            <ResponsiveTransactionTable
                                table={table}
                                columns={columns}
                                emptyMessage={__('No transactions found.')}
                                renderRow={renderTransactionRow}
                                sorting={sorting}
                                rowSelection={rowSelection}
                                daySummaryOf={daySummaryOf}
                                isGroupedByDay={isGroupedByDay}
                                selectionMode={selectionMode.forRows}
                                listRow={{
                                    categories,
                                    labels,
                                    categorizingIds,
                                }}
                                rowActions={rowActions}
                                topRow={
                                    showAiConsentBanner ? (
                                        <div className="flex flex-col gap-3 bg-gradient-to-r from-violet-50 via-sky-50 to-rose-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:from-violet-950/30 dark:via-sky-950/20 dark:to-rose-950/20">
                                            <div className="flex gap-3 sm:flex-1">
                                                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-white/80 shadow-sm dark:bg-white/10">
                                                    <AiSparkleIcon className="h-5 w-5" />
                                                </div>
                                                <div className="max-w-80 sm:max-w-none">
                                                    <p className="font-medium">
                                                        {__(
                                                            'Let AI categorize your transactions',
                                                        )}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {__(
                                                            'Give your consent and our AI will suggest categories for your transactions automatically.',
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    onClick={
                                                        handleDismissAiConsent
                                                    }
                                                    disabled={aiConsentSaving}
                                                    className="opacity-20 transition-all duration-300 hover:opacity-100"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={__('Dismiss')}
                                                >
                                                    <X />
                                                </Button>
                                                <Button
                                                    onClick={handleEnableAi}
                                                    disabled={aiConsentSaving}
                                                    variant="secondary"
                                                    className="max-w-[calc(var(--spacing)_*_86)] bg-white px-6! dark:bg-white/10"
                                                >
                                                    {__('Enable AI')}
                                                    <ChevronRight />
                                                </Button>
                                            </div>
                                        </div>
                                    ) : null
                                }
                            />

                            <DataTablePagination
                                displayedCount={allTransactions.length}
                                rowCountLabel={__('transactions loaded')}
                            >
                                {nextCursor && (
                                    <div ref={loadMoreRef}>
                                        <Button
                                            onClick={handleLoadMore}
                                            disabled={isLoadingMore}
                                            variant="outline"
                                        >
                                            {isLoadingMore ? (
                                                <>
                                                    <Spinner />
                                                    {__('Loading')}
                                                </>
                                            ) : (
                                                <>{__('Load more')}</>
                                            )}
                                        </Button>
                                    </div>
                                )}
                            </DataTablePagination>
                        </>
                    )}
                </div>
            </div>

            <TransactionRowDialogs
                editing={editing}
                categories={categories}
                accounts={accounts}
                banks={banks}
                automationRules={automationRules}
                onDuplicated={refreshTransactions}
            />

            <AlertDialog
                open={!!deleteTransaction && !isBulkDeleteMode}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTransaction(null);
                        setUpdateBalanceOnDelete(true);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Delete Transaction')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {__(
                                'Are you sure you want to delete this transaction? This action cannot be undone.',
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {deleteTransaction !== null &&
                        manualAccountIds.has(deleteTransaction.account_id) && (
                            <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-muted/40 p-3 text-sm">
                                <Checkbox
                                    checked={updateBalanceOnDelete}
                                    onCheckedChange={(checked) =>
                                        setUpdateBalanceOnDelete(
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <span className="text-muted-foreground">
                                    {__(
                                        'Update the current balance of the manual account to reflect this change.',
                                    )}
                                </span>
                            </label>
                        )}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDelete}
                            disabled={isDeleting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {isDeleting ? __('Deleting...') : __('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog
                open={!!deleteTransaction && isBulkDeleteMode}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTransaction(null);
                        setIsBulkDeleteMode(false);
                        setBulkDeleteConfirmation('');
                        setUpdateBalanceOnDelete(true);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__('Delete Transactions')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'This action cannot be undone. To confirm, type',
                            )}{' '}
                            <span className="font-semibold text-foreground">
                                {bulkDeleteConfirmationText}
                            </span>
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        value={bulkDeleteConfirmation}
                        onChange={(e) =>
                            setBulkDeleteConfirmation(e.target.value)
                        }
                        placeholder={bulkDeleteConfirmationText}
                        disabled={isBulkDeleting}
                        autoFocus
                    />
                    {bulkDeleteTouchesManualAccount && (
                        <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-muted/40 p-3 text-sm">
                            <Checkbox
                                checked={updateBalanceOnDelete}
                                onCheckedChange={(checked) =>
                                    setUpdateBalanceOnDelete(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <span className="text-muted-foreground">
                                {__(
                                    'Update the current balance of the affected manual accounts to reflect this change.',
                                )}
                            </span>
                        </label>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setDeleteTransaction(null);
                                setIsBulkDeleteMode(false);
                                setBulkDeleteConfirmation('');
                            }}
                            disabled={isBulkDeleting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleBulkDelete}
                            disabled={
                                isBulkDeleting ||
                                bulkDeleteConfirmation !==
                                    bulkDeleteConfirmationText
                            }
                        >
                            {isBulkDeleting ? __('Deleting...') : __('Delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <BulkActionsBar
                selectedCount={selectedCount}
                isSelectingAll={isSelectingAll}
                categories={categories}
                labels={labels}
                onCategoryChange={handleBulkCategoryChange}
                onLabelsChange={handleBulkLabelsChange}
                onLabelCreated={handleLabelCreated}
                onDelete={handleBulkDeleteClick}
                onReEvaluateRules={handleBulkReEvaluateRules}
                onSelectAll={handleSelectAll}
                onSelectOnlyVisible={selectOnlyVisible}
                onClear={selectionMode.clear}
                isUpdating={isBulkUpdating || isReEvaluating}
                selectionMode={selectionMode}
            />
        </AppSidebarLayout>
    );
}
