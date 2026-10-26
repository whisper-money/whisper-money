import { useInMemoryDaySummaries } from '@/hooks/use-day-summaries';
import { useLocale } from '@/hooks/use-locale';
import { useMobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { useBalanceOwningAccountIds } from '@/hooks/use-owns-balance-updates';
import { useStoredColumnVisibility } from '@/hooks/use-stored-column-visibility';
import { useTransactionDayGrouping } from '@/hooks/use-transaction-day-grouping';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { reloadPage } from '@/lib/leave-page';
import { isSplitPart } from '@/lib/transaction-splits';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    ColumnFiltersState,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    Row,
    SortingState,
    useReactTable,
} from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import { isWithinInterval, parseISO } from 'date-fns';
import { ExternalLink } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { toast } from 'sonner';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { BulkActionsBar } from '@/components/transactions/bulk-actions-bar';
import { ResponsiveTransactionTable } from '@/components/transactions/responsive-transaction-table';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { createTransactionColumns } from '@/components/transactions/transaction-columns';
import { TransactionFilters as TransactionFiltersComponent } from '@/components/transactions/transaction-filters';
import { TransactionListSkeleton } from '@/components/transactions/transaction-list-skeleton';
import { TransactionRowDialogs } from '@/components/transactions/transaction-row-dialogs';
import { TransactionTableRow } from '@/components/transactions/transaction-table-row';
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
import { Spinner } from '@/components/ui/spinner';
import { consoleDebug } from '@/lib/debug';
import { applyBulkLabels } from '@/lib/transaction-bulk-labels';
import { reEvaluateTransactionRules } from '@/lib/transaction-re-evaluation';
import { transactionSyncService } from '@/services/transaction-sync';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type TransactionFilters as Filters,
    type ServerTransaction,
    type Transaction,
} from '@/types/transaction';
import { UUID } from '@/types/uuid';
import { toMajorUnits } from '@/utils/currency';

const NO_LABELS: Label[] = [];

export interface TransactionListProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels?: Label[];
    automationRules?: AutomationRule[];
    accountId?: UUID;
    transactions?: Transaction[]; // Server-provided transactions to render
    pageSize?: number;
    hideAccountFilter?: boolean;
    showActionsMenu?: boolean;
    headerActions?: ReactNode;
    maxHeight?: number;
    hideColumns?: string[];
    onBalanceUpdated?: () => void;
    /**
     * Called after a transaction in the list is edited or deleted, for pages
     * whose figures are computed from the ledger on the server.
     */
    onTransactionsChanged?: () => void;
    /** A label every listed transaction already carries; hidden from the rows. */
    hiddenLabelId?: UUID;
    /** Credit card repayments by transaction id, with the statement each pays. */
    statementPayments?: Map<string, CreditCardPayment>;
}

export function TransactionList({
    categories,
    accounts,
    banks,
    labels: initialLabels,
    automationRules = [],
    accountId,
    transactions: providedTransactions,
    pageSize = 25,
    hideAccountFilter = false,
    showActionsMenu = true,
    headerActions,
    maxHeight,
    hideColumns = [],
    onBalanceUpdated,
    onTransactionsChanged,
    hiddenLabelId,
    statementPayments,
}: TransactionListProps) {
    const locale = useLocale();
    const [transactions, setTransactions] = useState<ServerTransaction[]>([]);
    const [isLoading, setIsLoading] = useState(
        providedTransactions === undefined,
    );
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'transaction_date', desc: true },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useStoredColumnVisibility();
    const [rowSelection, setRowSelection] = useState({});
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
    });
    const editing = useTransactionRowEditing({
        initialLabels: initialLabels ?? NO_LABELS,
        setRows: setTransactions,
        onRowUpdated: onTransactionsChanged,
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
    const [isDeleting, setIsDeleting] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [isBulkUpdating, setIsBulkUpdating] = useState(false);
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
                            transaction.labels?.map((label) => label.id) ??
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
            const description = (
                tx.description ??
                tx.description ??
                ''
            ).toLowerCase();
            const notes = (tx.notes ?? tx.notes ?? '').toLowerCase();

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

    const manualAccountIds = useBalanceOwningAccountIds(accounts);

    const filteredTransactions = useMemo(() => {
        return transactions.filter((transaction) => {
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

            if (
                filters.amountMin !== null &&
                amountInMajorUnits < filters.amountMin
            ) {
                return false;
            }
            if (
                filters.amountMax !== null &&
                amountInMajorUnits > filters.amountMax
            ) {
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
        });
    }, [transactions, filters, accountId, searchMatchedIds]);

    const sortedTransactions = useMemo(() => {
        if (sorting.length === 0) {
            return filteredTransactions;
        }

        const sorted = [...filteredTransactions];
        sorted.sort((a, b) => {
            for (const sort of sorting) {
                const { id, desc } = sort;
                let comparison = 0;

                if (id === 'transaction_date') {
                    const dateA = parseISO(a.transaction_date).getTime();
                    const dateB = parseISO(b.transaction_date).getTime();
                    comparison = dateA - dateB;
                } else if (id === 'amount') {
                    comparison = parseFloat(a.amount) - parseFloat(b.amount);
                } else if (id === 'description') {
                    comparison = a.description.localeCompare(b.description);
                } else if (id === 'account') {
                    const accountA = a.account?.name || '';
                    const accountB = b.account?.name || '';
                    comparison = accountA.localeCompare(accountB);
                } else if (id === 'category') {
                    const categoryA = a.category?.name || '';
                    const categoryB = b.category?.name || '';
                    comparison = categoryA.localeCompare(categoryB);
                } else if (id === 'creditor_name') {
                    comparison = (a.creditor_name || '').localeCompare(
                        b.creditor_name || '',
                    );
                } else if (id === 'debtor_name') {
                    comparison = (a.debtor_name || '').localeCompare(
                        b.debtor_name || '',
                    );
                }

                if (comparison !== 0) {
                    return desc ? -comparison : comparison;
                }
            }
            return 0;
        });

        return sorted;
    }, [filteredTransactions, sorting]);

    const displayedTransactions = useMemo(() => {
        return sortedTransactions.slice(0, displayedCount);
    }, [sortedTransactions, displayedCount]);

    // Over every row the filters keep, not only the drawn slice, so a day cut
    // by "load more" still says what it holds in full.
    const daySummaryOf = useInMemoryDaySummaries(filteredTransactions);

    const selectionMode = useMobileSelectionMode({
        rowSelection,
        setRowSelection,
        rows: displayedTransactions,
    });

    const { isGroupedByDay, tableColumnVisibility, onColumnVisibilityChange } =
        useTransactionDayGrouping({
            sorting,
            columnVisibility,
            setColumnVisibility,
        });

    async function handleBulkReEvaluateRules() {
        const selectedIds = Object.keys(rowSelection);
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');
        consoleDebug(`Selected ${selectedIds.length} transactions`);

        if (selectedIds.length === 0) {
            consoleDebug('❌ No transactions selected');
            return;
        }

        setIsReEvaluating(true);

        try {
            const outcome = await reEvaluateTransactionRules({
                transaction_ids: selectedIds,
            });

            // A queued job will still run, so the selection is handed off
            // either way; keeping it would invite a duplicate job.
            if (outcome !== 'failed') {
                setRowSelection({});
            }
        } finally {
            setIsReEvaluating(false);
        }
    }

    const columns = useMemo(() => {
        const allColumns = createTransactionColumns({
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
            hiddenLabelId,
            statementPayments,
        });

        if (hideColumns.length === 0) {
            return allColumns;
        }

        return allColumns.filter((column) => {
            const columnId =
                'accessorKey' in column ? column.accessorKey : column.id;
            return !hideColumns.includes(columnId as string);
        });
    }, [
        accounts,
        banks,
        categories,
        labels,
        locale,
        rowActions,
        updateTransaction,
        handleCategorized,
        hideColumns,
        tableColumnVisibility,
        isGroupedByDay,
        hiddenLabelId,
        statementPayments,
    ]);

    const table = useReactTable({
        data: displayedTransactions,
        columns,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        getRowId: (row) => row.id.toString(),
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        onColumnVisibilityChange,
        onRowSelectionChange: setRowSelection,
        enableRowSelection: true,
        state: {
            sorting,
            columnFilters,
            columnVisibility: tableColumnVisibility,
            rowSelection,
        },
    });

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

    async function handleDelete() {
        if (!deleteTransaction) {
            return;
        }

        setIsDeleting(true);
        const balanceWasUpdated =
            updateBalanceOnDelete &&
            manualAccountIds.has(deleteTransaction.account_id);
        try {
            await transactionSyncService.delete(deleteTransaction.id, {
                updateBalance: balanceWasUpdated,
            });
            if (balanceWasUpdated) {
                onBalanceUpdated?.();
            }
            onTransactionsChanged?.();
            setTransactions((previous) =>
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
        const selectedIds = Object.keys(rowSelection);
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            await transactionSyncService.updateMany(selectedIds, {
                category_id: categoryId,
            });

            const categoriesMap = new Map(
                categories.map((category) => [category.id, category]),
            );
            const selectedCategory = categoryId
                ? categoriesMap.get(categoryId) || null
                : null;

            setTransactions((previous) =>
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
            onTransactionsChanged?.();

            setRowSelection({});
        } catch (error) {
            console.error('Failed to update transactions:', error);
        } finally {
            setIsBulkUpdating(false);
        }
    }

    async function handleBulkLabelsChange(labelIds: string[]) {
        const selectedIds = Object.keys(rowSelection);
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            await transactionSyncService.updateMany(selectedIds, {
                label_ids: labelIds,
            });

            setTransactions((previous) =>
                applyBulkLabels(previous, selectedIds, labelIds, labels),
            );

            setRowSelection({});
        } catch (error) {
            console.error('Failed to update transactions with labels:', error);
        } finally {
            setIsBulkUpdating(false);
        }
    }

    function handleBulkDeleteClick() {
        const selectedIds = Object.keys(rowSelection);

        if (selectedIds.length === 0) {
            return;
        }

        // One part of a split cannot go on its own, and refusing the whole
        // batch up front beats failing halfway through it.
        const selectedSplitParts = filteredTransactions.filter(
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

        const firstSelectedTransaction = filteredTransactions.find(
            (t) => t.id.toString() === selectedIds[0],
        );

        if (firstSelectedTransaction) {
            setIsBulkDeleteMode(true);
            setDeleteTransaction(firstSelectedTransaction);
        }
    }

    async function handleBulkDelete() {
        const selectedIds = Object.keys(rowSelection);
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkDeleting(true);
        const balanceMayHaveUpdated =
            updateBalanceOnDelete &&
            transactions.some(
                (transaction) =>
                    selectedIds.includes(transaction.id) &&
                    manualAccountIds.has(transaction.account_id),
            );
        try {
            await transactionSyncService.deleteMany(selectedIds, {
                updateBalance: balanceMayHaveUpdated,
            });
            if (balanceMayHaveUpdated) {
                onBalanceUpdated?.();
            }
            onTransactionsChanged?.();
            setTransactions((previous) =>
                previous.filter(
                    (transaction) => !selectedIds.includes(transaction.id),
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transactions:', error);
        } finally {
            setIsBulkDeleting(false);
        }
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
                    {...rowActions}
                />
            );
        },
        [rowActions],
    );

    return (
        <>
            <div className="space-y-4">
                <TransactionFiltersComponent
                    filters={filters}
                    onFiltersChange={setFilters}
                    categories={categories}
                    labels={labels}
                    accounts={accounts}
                    hideAccountFilter={hideAccountFilter}
                    actions={
                        <div className="flex flex-wrap justify-end gap-2">
                            {showActionsMenu && (
                                <TransactionActionsMenu
                                    transactions={transactions}
                                    hasTransactionalAccounts={
                                        filterTransactionalAccounts(accounts)
                                            .length > 0
                                    }
                                    onReEvaluateComplete={() => {
                                        setRowSelection({});
                                        setTimeout(() => {
                                            reloadPage();
                                        }, 500);
                                    }}
                                />
                            )}
                            {headerActions}
                            <DataTableViewOptions
                                table={table}
                                hideColumnTextOnMobile={false}
                            />
                        </div>
                    }
                />

                {isLoading || isSearching ? (
                    <TransactionListSkeleton />
                ) : (
                    <>
                        <ResponsiveTransactionTable
                            table={table}
                            columns={columns}
                            emptyMessage={__('No transactions found.')}
                            renderRow={renderTransactionRow}
                            maxHeight={maxHeight}
                            sorting={sorting}
                            rowSelection={rowSelection}
                            daySummaryOf={daySummaryOf}
                            isGroupedByDay={isGroupedByDay}
                            selectionMode={selectionMode.forRows}
                            listRow={{
                                categories,
                                labels,
                                hiddenLabelId,
                                statementPayments,
                            }}
                            rowActions={rowActions}
                        />

                        <DataTablePagination
                            displayedCount={displayedCount}
                            total={sortedTransactions.length}
                            rowCountLabel={__('transactions total')}
                        >
                            {displayedCount < sortedTransactions.length && (
                                <Button
                                    onClick={loadMore}
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
                            )}
                            {accountId && (
                                <Button asChild variant="outline">
                                    <Link
                                        href={
                                            transactionsIndex({
                                                query: {
                                                    account_ids: accountId,
                                                },
                                            }).url
                                        }
                                    >
                                        <ExternalLink />
                                        {__('View in Transactions')}
                                    </Link>
                                </Button>
                            )}
                        </DataTablePagination>
                    </>
                )}
            </div>

            <TransactionRowDialogs
                editing={editing}
                categories={categories}
                accounts={accounts}
                banks={banks}
                automationRules={automationRules}
                onDuplicated={reloadPage}
            />

            <AlertDialog
                open={!!deleteTransaction}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTransaction(null);
                        setIsBulkDeleteMode(false);
                        setUpdateBalanceOnDelete(true);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Delete Transaction')}

                            {isBulkDeleteMode ? 's' : ''}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {isBulkDeleteMode
                                ? `Are you sure you want to delete ${Object.keys(rowSelection).length} transactions? This action cannot be undone.`
                                : 'Are you sure you want to delete this transaction? This action cannot be undone.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {(isBulkDeleteMode
                        ? transactions.some(
                              (transaction) =>
                                  Object.keys(rowSelection).includes(
                                      transaction.id,
                                  ) &&
                                  manualAccountIds.has(transaction.account_id),
                          )
                        : deleteTransaction !== null &&
                          manualAccountIds.has(
                              deleteTransaction.account_id,
                          )) && (
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
                                    'Update the current balance of the manual account to reflect this change.',
                                )}
                            </span>
                        </label>
                    )}
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            disabled={isDeleting || isBulkDeleting}
                        >
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={
                                isBulkDeleteMode
                                    ? handleBulkDelete
                                    : handleDelete
                            }
                            disabled={isDeleting || isBulkDeleting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {isDeleting || isBulkDeleting
                                ? 'Deleting...'
                                : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <BulkActionsBar
                selectedCount={Object.keys(rowSelection).length}
                categories={categories}
                labels={labels}
                onCategoryChange={handleBulkCategoryChange}
                onLabelsChange={handleBulkLabelsChange}
                onLabelCreated={handleLabelCreated}
                onDelete={handleBulkDeleteClick}
                onReEvaluateRules={handleBulkReEvaluateRules}
                onClear={selectionMode.clear}
                isUpdating={isBulkUpdating || isReEvaluating}
                selectionMode={selectionMode}
            />
        </>
    );
}
