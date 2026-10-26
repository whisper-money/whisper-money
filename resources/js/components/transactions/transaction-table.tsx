import { BulkActionsBar } from '@/components/transactions/bulk-actions-bar';
import { ResponsiveTransactionTable } from '@/components/transactions/responsive-transaction-table';
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
import { useMobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { useStoredColumnVisibility } from '@/hooks/use-stored-column-visibility';
import { useTransactionBulkActions } from '@/hooks/use-transaction-bulk-actions';
import { useTransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import {
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import {
    type TransactionTablePagination,
    type TransactionTableSource,
} from '@/types/transaction-table-source';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import {
    type ColumnFiltersState,
    getCoreRowModel,
    getFilteredRowModel,
    type Row,
    type RowSelectionState,
    useReactTable,
} from '@tanstack/react-table';
import { type VirtualItem, type Virtualizer } from '@tanstack/react-virtual';
import {
    type ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

const NO_HIDDEN_COLUMNS: string[] = [];

/** What the toolbar beside the filters can reach of the table. */
export interface TransactionTableToolbar {
    /** The picker for the table's columns, for the page to place. */
    columnPicker: ReactNode;
    /** The labels, including any a dialog created since the page loaded. */
    labels: Label[];
    clearSelection: () => void;
}

interface TransactionTableProps {
    source: TransactionTableSource;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules: AutomationRule[];
    /** What sits beside the filters: menus, export, the column picker. */
    renderToolbar: (toolbar: TransactionTableToolbar) => ReactNode;
    /** Keep the column picker's text on narrow screens. */
    showColumnPickerTextOnMobile?: boolean;
    hideAccountFilter?: boolean;
    enableSavedFilters?: boolean;
    /** Columns this page never shows, whatever the saved choice. */
    hideColumns?: string[];
    /** A label every listed transaction already carries; hidden from the rows. */
    hiddenLabelId?: UUID;
    /** Credit card repayments by transaction id, with the statement each pays. */
    statementPayments?: Map<string, CreditCardPayment>;
    /** Rows AI is categorizing right now, drawn with a spinner. */
    categorizingIds?: Set<string>;
    /** Marks a row that arrived since the last visit. */
    isNewRow?: (transaction: ServerTransaction) => boolean;
    /** Added to every cell of the desktop rows. */
    rowCellClassName?: string;
    /** Scrolls inside a box this tall instead of with the window. */
    maxHeight?: number;
    /**
     * The table sits inside a card rather than straight in the page's padded
     * content, so on a phone its compact list keeps its frame instead of
     * running edge to edge.
     */
    framedOnPhone?: boolean;
    /** Drawn above the first row, such as a banner. */
    topRow?: ReactNode;
    /** Beside "Load more" below the rows. */
    footerActions?: ReactNode;
    /** Make the user type the count before deleting several rows. */
    confirmBulkByTyping?: boolean;
    /** Toast how many rows a bulk edit changed, or that it failed. */
    announceBulkOutcome?: boolean;
    onBalanceUpdated?: () => void;
    /**
     * Called after a transaction is edited or deleted, for pages whose figures
     * are computed from the ledger on the server.
     */
    onTransactionsChanged?: () => void;
}

/**
 * The one transactions table: rows with their menus and dialogs, selection
 * and bulk actions, day headers, the table or the compact list, and the
 * footer, around a source that says where the rows come from.
 */
export function TransactionTable({
    source,
    categories,
    accounts,
    banks,
    labels: initialLabels,
    automationRules,
    renderToolbar,
    showColumnPickerTextOnMobile = false,
    hideAccountFilter = false,
    enableSavedFilters = false,
    hideColumns = NO_HIDDEN_COLUMNS,
    hiddenLabelId,
    statementPayments,
    categorizingIds,
    isNewRow,
    rowCellClassName,
    maxHeight,
    framedOnPhone = false,
    topRow,
    footerActions,
    confirmBulkByTyping = false,
    announceBulkOutcome = false,
    onBalanceUpdated,
    onTransactionsChanged,
}: TransactionTableProps) {
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useStoredColumnVisibility();
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});

    const editing = useTransactionRowEditing({
        initialLabels,
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
        matchingFilters: source.matchingFilters,
        onServerChanged: source.refreshRows,
        announceOutcome: announceBulkOutcome,
        onBalanceUpdated,
        onTransactionsChanged,
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
        rows: source.rows,
        onClear: selectOnlyVisible,
    });

    const columns = useMemo(() => {
        const allColumns = createTransactionColumns({
            categories,
            accounts,
            banks,
            labels,
            ...rowActions,
            onUpdate: updateTransaction,
            onCategorized: handleCategorized,
            hiddenLabelId,
            statementPayments,
            categorizingIds,
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
        rowActions,
        updateTransaction,
        handleCategorized,
        hideColumns,
        hiddenLabelId,
        statementPayments,
        categorizingIds,
    ]);

    const table = useReactTable({
        data: source.rows,
        columns,
        onColumnFiltersChange: setColumnFilters,
        getRowId: (row) => row.id.toString(),
        getCoreRowModel: getCoreRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        onRowSelectionChange: setRowSelection,
        enableRowSelection: true,
        state: {
            columnFilters,
            columnVisibility,
            rowSelection,
        },
    });

    const renderTransactionRow = useCallback(
        (
            row: Row<ServerTransaction>,
            virtualRow: VirtualItem,
            rowVirtualizer: Virtualizer<HTMLDivElement, Element>,
        ) => (
            <TransactionTableRow
                key={row.id}
                row={row}
                virtualRow={virtualRow}
                rowVirtualizer={rowVirtualizer}
                isNew={isNewRow?.(row.original) ?? false}
                cellClassName={rowCellClassName}
                {...rowActions}
            />
        ),
        [rowActions, isNewRow, rowCellClassName],
    );

    const clearSelection = useCallback(() => setRowSelection({}), []);

    return (
        <>
            <div className="space-y-4">
                <TransactionFiltersComponent
                    filters={source.filters}
                    onFiltersChange={source.onFiltersChange}
                    categories={categories}
                    labels={labels}
                    accounts={accounts}
                    hideAccountFilter={hideAccountFilter}
                    enableSavedFilters={enableSavedFilters}
                    actions={renderToolbar({
                        columnPicker: (
                            <DataTableViewOptions
                                table={table}
                                hideColumnTextOnMobile={
                                    !showColumnPickerTextOnMobile
                                }
                            />
                        ),
                        labels,
                        clearSelection,
                    })}
                />

                {source.isLoading ? (
                    <TransactionListSkeleton framedOnPhone={framedOnPhone} />
                ) : (
                    <>
                        <ResponsiveTransactionTable
                            table={table}
                            columns={columns}
                            emptyMessage={__('No transactions found.')}
                            renderRow={renderTransactionRow}
                            maxHeight={maxHeight}
                            rowSelection={rowSelection}
                            daySummaryOf={source.daySummaryOf}
                            selectionMode={selectionMode.forRows}
                            listRow={{
                                categories,
                                labels,
                                categorizingIds,
                                hiddenLabelId,
                                statementPayments,
                            }}
                            rowActions={rowActions}
                            topRow={topRow}
                            framedOnPhone={framedOnPhone}
                        />

                        <TransactionTableFooter
                            pagination={source.pagination}
                            actions={footerActions}
                        />
                    </>
                )}
            </div>

            <TransactionRowDialogs
                editing={editing}
                categories={categories}
                accounts={accounts}
                banks={banks}
                automationRules={automationRules}
                onDuplicated={source.reload}
            />

            <TransactionDeleteDialogs
                dialog={bulk.deleteDialog}
                confirmBulkByTyping={confirmBulkByTyping}
            />

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

/**
 * How many rows the table shows and the button for more, which loads by
 * itself once scrolled near when the source pages that way.
 */
function TransactionTableFooter({
    pagination,
    actions,
}: {
    pagination: TransactionTablePagination;
    actions?: ReactNode;
}) {
    const { hasMore, loadMore, loadsOnScroll } = pagination;
    const loadMoreRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const element = loadMoreRef.current;
        if (!element || !hasMore || !loadsOnScroll) {
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

        observer.observe(element);
        return () => observer.disconnect();
    }, [hasMore, loadMore, loadsOnScroll]);

    return (
        <DataTablePagination
            displayedCount={pagination.displayedCount}
            total={pagination.total}
            rowCountLabel={pagination.rowCountLabel}
        >
            {hasMore && (
                <div ref={loadMoreRef}>
                    <Button
                        onClick={loadMore}
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
            {actions}
        </DataTablePagination>
    );
}
