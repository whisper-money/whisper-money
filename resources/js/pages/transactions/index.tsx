import { useLocale } from '@/hooks/use-locale';
import { useMobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { usePollJobStatus } from '@/hooks/use-poll-job-status';
import {
    useServerTransactionSource,
    type AppliedFilters,
} from '@/hooks/use-server-transaction-source';
import { useStoredColumnVisibility } from '@/hooks/use-stored-column-visibility';
import { useTransactionBulkActions } from '@/hooks/use-transaction-bulk-actions';
import { useTransactionDayGrouping } from '@/hooks/use-transaction-day-grouping';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
import { getCoreRowModel, Row, useReactTable } from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import axios from 'axios';
import { ChevronRight, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import HeadingSmall from '@/components/heading-small';
import { BulkActionsBar } from '@/components/transactions/bulk-actions-bar';
import { ResponsiveTransactionTable } from '@/components/transactions/responsive-transaction-table';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { createTransactionColumns } from '@/components/transactions/transaction-columns';
import { TransactionDeleteDialogs } from '@/components/transactions/transaction-delete-dialogs';
import { TransactionExportButton } from '@/components/transactions/transaction-export-button';
import { TransactionFilters as TransactionFiltersComponent } from '@/components/transactions/transaction-filters';
import { TransactionListSkeleton } from '@/components/transactions/transaction-list-skeleton';
import { TransactionRowDialogs } from '@/components/transactions/transaction-row-dialogs';
import { TransactionTableRow } from '@/components/transactions/transaction-table-row';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Button } from '@/components/ui/button';
import { DataTablePagination } from '@/components/ui/data-table-pagination';
import { DataTableViewOptions } from '@/components/ui/data-table-view-options';
import { Spinner } from '@/components/ui/spinner';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { type CursorPaginatedResponse } from '@/lib/cursor-pagination';
import { isNewSince } from '@/lib/new-transactions';
import { type ServerDayTotal } from '@/lib/transaction-day-groups';
import { describeActiveFilters } from '@/lib/transaction-filter-chips';
import { status as categorizationStatus } from '@/routes/ai/categorization';
import {
    dismiss as dismissConsent,
    store as storeConsent,
} from '@/routes/ai/consent';
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
import { type ServerTransaction } from '@/types/transaction';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Transactions',
        href: transactionsIndex.url(),
    },
];

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

    const source = useServerTransactionSource({
        transactions: serverTransactions,
        dayTotals: initialDayTotals,
        appliedFilters,
    });
    const { rows: allTransactions, filters, sorting, pagination } = source;
    const { hasMore, loadMore, loadsOnScroll } = pagination;
    const refreshTransactions = source.reload;

    // Frozen at mount so per-row "new" marks stay stable for the whole visit.
    // The marker is server-side (per user), so it's shared across devices; the
    // controller advances it on load using the newest created_at it served.
    const [lastVisitAtMount] = useState<string | null>(() => lastVisitAt);

    const [columnVisibility, setColumnVisibility] = useStoredColumnVisibility();
    const [rowSelection, setRowSelection] = useState({});
    const editing = useTransactionRowEditing({
        initialLabels,
        setRows: source.setRows,
    });
    const {
        labels,
        handleLabelCreated,
        rowActions,
        updateTransaction,
        handleCategorized,
    } = editing;
    const bulk = useTransactionBulkActions({
        rows: source.heldRows,
        setRows: source.setRows,
        rowSelection,
        setRowSelection,
        categories,
        labels,
        accounts,
        editing,
        matchingFilters: source.matchingFilters,
        onServerChanged: refreshTransactions,
        announceOutcome: true,
    });
    const { selectOnlyVisible } = bulk;

    // A new query replaces the rows, so nothing stays ticked across it.
    const [seenQueryKey, setSeenQueryKey] = useState(source.queryKey);

    if (source.queryKey !== seenQueryKey) {
        setSeenQueryKey(source.queryKey);
        setRowSelection({});
        selectOnlyVisible();
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

    // Auto-load more when the sentinel becomes visible
    const loadMoreRef = useRef<HTMLDivElement | null>(null);
    useEffect(() => {
        const el = loadMoreRef.current;
        if (!el || !hasMore || !loadsOnScroll) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    loadMore();
                }
            },
            { rootMargin: '200px' },
        );

        observer.observe(el);
        return () => observer.disconnect();
    }, [hasMore, loadMore, loadsOnScroll]);

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
        manualSorting: source.manualSorting,
        onSortingChange: source.onSortingChange,
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

    const hasTransactionalAccounts = useMemo(
        () => filterTransactionalAccounts(accounts).length > 0,
        [accounts],
    );

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
                        onFiltersChange={source.onFiltersChange}
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
                                        params={source.queryParams}
                                        loadedIds={allTransactions.map(
                                            (transaction) => transaction.id,
                                        )}
                                        hasMore={pagination.hasMore}
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

                    {source.isLoading ? (
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
                                daySummaryOf={source.daySummaryOf}
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
                                displayedCount={pagination.displayedCount}
                                rowCountLabel={pagination.rowCountLabel}
                            >
                                {pagination.hasMore && (
                                    <div ref={loadMoreRef}>
                                        <Button
                                            onClick={pagination.loadMore}
                                            disabled={pagination.isLoadingMore}
                                            variant="outline"
                                        >
                                            {pagination.isLoadingMore ? (
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

            <TransactionDeleteDialogs
                dialog={bulk.deleteDialog}
                confirmBulkByTyping
            />

            <BulkActionsBar
                {...bulk.barProps}
                categories={categories}
                labels={labels}
                onLabelCreated={handleLabelCreated}
                onClear={selectionMode.clear}
                selectionMode={selectionMode}
            />
        </AppSidebarLayout>
    );
}
