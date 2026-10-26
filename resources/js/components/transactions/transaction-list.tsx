import { useInMemoryTransactionSource } from '@/hooks/use-in-memory-transaction-source';
import { useLocale } from '@/hooks/use-locale';
import { useMobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { useStoredColumnVisibility } from '@/hooks/use-stored-column-visibility';
import { useTransactionBulkActions } from '@/hooks/use-transaction-bulk-actions';
import { useTransactionDayGrouping } from '@/hooks/use-transaction-day-grouping';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { reloadPage } from '@/lib/leave-page';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    ColumnFiltersState,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    Row,
    useReactTable,
} from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import { ExternalLink } from 'lucide-react';
import { useCallback, useMemo, useState, type ReactNode } from 'react';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { BulkActionsBar } from '@/components/transactions/bulk-actions-bar';
import { ResponsiveTransactionTable } from '@/components/transactions/responsive-transaction-table';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { createTransactionColumns } from '@/components/transactions/transaction-columns';
import { TransactionDeleteDialogs } from '@/components/transactions/transaction-delete-dialogs';
import { TransactionFilters as TransactionFiltersComponent } from '@/components/transactions/transaction-filters';
import { TransactionListSkeleton } from '@/components/transactions/transaction-list-skeleton';
import { TransactionRowDialogs } from '@/components/transactions/transaction-row-dialogs';
import { TransactionTableRow } from '@/components/transactions/transaction-table-row';
import { Button } from '@/components/ui/button';
import { DataTablePagination } from '@/components/ui/data-table-pagination';
import { DataTableViewOptions } from '@/components/ui/data-table-view-options';
import { Spinner } from '@/components/ui/spinner';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction, type Transaction } from '@/types/transaction';
import { UUID } from '@/types/uuid';

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
    const source = useInMemoryTransactionSource({
        transactions: providedTransactions,
        accountId,
        pageSize,
    });
    const { sorting, filters, pagination } = source;
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useStoredColumnVisibility();
    const [rowSelection, setRowSelection] = useState({});
    const editing = useTransactionRowEditing({
        initialLabels: initialLabels ?? NO_LABELS,
        setRows: source.setRows,
        onRowUpdated: onTransactionsChanged,
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
        onBalanceUpdated,
        onTransactionsChanged,
    });
    const selectionMode = useMobileSelectionMode({
        rowSelection,
        setRowSelection,
        rows: source.rows,
    });

    const { isGroupedByDay, tableColumnVisibility, onColumnVisibilityChange } =
        useTransactionDayGrouping({
            sorting,
            columnVisibility,
            setColumnVisibility,
        });

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
        data: source.rows,
        columns,
        manualSorting: source.manualSorting,
        onSortingChange: source.onSortingChange,
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
                    onFiltersChange={source.onFiltersChange}
                    categories={categories}
                    labels={labels}
                    accounts={accounts}
                    hideAccountFilter={hideAccountFilter}
                    actions={
                        <div className="flex flex-wrap justify-end gap-2">
                            {showActionsMenu && (
                                <TransactionActionsMenu
                                    transactions={source.heldRows}
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

                {source.isLoading ? (
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
                            daySummaryOf={source.daySummaryOf}
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
                            displayedCount={pagination.displayedCount}
                            total={pagination.total}
                            rowCountLabel={pagination.rowCountLabel}
                        >
                            {pagination.hasMore && (
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

            <TransactionDeleteDialogs dialog={bulk.deleteDialog} />

            <BulkActionsBar
                {...bulk.barProps}
                categories={categories}
                labels={labels}
                onLabelCreated={handleLabelCreated}
                onClear={selectionMode.clear}
                selectionMode={selectionMode}
            />
        </>
    );
}
