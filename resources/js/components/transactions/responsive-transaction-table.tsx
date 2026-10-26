import { useTransactionDayHeaders } from '@/components/transactions/transaction-day-header';
import { TransactionListRow } from '@/components/transactions/transaction-list-row';
import { TransactionRowContextMenuContent } from '@/components/transactions/transaction-row-context-menu';
import { TransactionSortMenu } from '@/components/transactions/transaction-sort-menu';
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import {
    DataTable,
    type DataTableMeasureProps,
    type DataTableRowGroup,
    useGroupedRowVirtualizer,
} from '@/components/ui/data-table';
import { useElementWidth } from '@/hooks/use-element-width';
import {
    type RowSelectionMode,
    useLongPressSelect,
} from '@/hooks/use-mobile-selection-mode';
import { type DaySummaryOf } from '@/lib/transaction-day-groups';
import { type TransactionRowActionHandlers } from '@/lib/transaction-row-actions';
import { cn } from '@/lib/utils';
import { type CreditCardPayment } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import {
    type ColumnDef,
    type Row,
    type RowSelectionState,
    type SortingState,
    type Table,
} from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import { Fragment, type MouseEvent, useRef } from 'react';

/**
 * Below 56rem of room the table's columns no longer fit and it turns
 * into a compact list. Measured on the list, not the viewport: the sidebar
 * takes its share, so a 1024px window with it open already gets the list.
 */
const LIST_LAYOUT_BELOW_PX = 56 * 16; // 56rem at the 16px root size the app keeps

/** What a row of the compact list shows beyond the transaction itself. */
export interface TransactionListRowOptions {
    categories: Category[];
    labels: Label[];
    /** Ids of transactions AI is categorizing in the background right now. */
    categorizingIds?: Set<string>;
    hiddenLabelId?: string;
    statementPayments?: Map<string, CreditCardPayment>;
}

interface ResponsiveTransactionTableProps {
    table: Table<ServerTransaction>;
    columns: ColumnDef<ServerTransaction>[];
    emptyMessage: string;
    renderRow: (
        row: Row<ServerTransaction>,
        virtualRow: VirtualItem,
        rowVirtualizer: Virtualizer<HTMLDivElement, Element>,
    ) => React.ReactNode;
    maxHeight?: number;
    topRow?: React.ReactNode;
    /** The table's state, handed in because the compiler memoizes past reads off it. */
    sorting: SortingState;
    rowSelection: RowSelectionState;
    /** What each day header says about its whole day, loaded rows or not. */
    daySummaryOf: DaySummaryOf;
    isGroupedByDay: boolean;
    selectionMode: RowSelectionMode;
    listRow: TransactionListRowOptions;
    rowActions: TransactionRowActionHandlers;
    /**
     * On a phone, run the compact list edge to edge out of the page's 24px
     * side padding (`p-6`), with no frame: one hairline where it starts and
     * the rows' own hairlines below. Leave it off where the list sits inside
     * a card, whose padding is not the page's.
     */
    fullBleedOnPhone?: boolean;
}

/**
 * A list of transactions as the table when there is room for it and as a
 * compact list when there is not, grouped by day the same way either way. Only one of the
 * two is ever mounted.
 */
export function ResponsiveTransactionTable({
    table,
    columns,
    emptyMessage,
    renderRow,
    maxHeight,
    topRow,
    sorting,
    rowSelection,
    daySummaryOf,
    isGroupedByDay,
    selectionMode,
    listRow,
    rowActions,
    fullBleedOnPhone = false,
}: ResponsiveTransactionTableProps) {
    const [containerRef, width] = useElementWidth<HTMLDivElement>();
    const layout =
        width !== null && width < LIST_LAYOUT_BELOW_PX ? 'list' : 'table';
    const selectionActive = Object.keys(rowSelection).length > 0;

    const dayHeaders = useTransactionDayHeaders({
        table,
        rowSelection,
        daySummaryOf,
        isGroupedByDay,
        layout,
        selectionActive,
    });

    return (
        <div
            ref={containerRef}
            className="min-w-0"
            data-layout={layout}
            data-testid="transaction-list-layout"
        >
            {/* Until it is measured, neither: mounting the table on a phone
                only to swap it for the list in the same commit costs every row. */}
            {width === null ? null : layout === 'list' ? (
                <TransactionCompactList
                    table={table}
                    sorting={sorting}
                    rowSelection={rowSelection}
                    selectionActive={selectionActive}
                    selectionMode={selectionMode}
                    emptyMessage={emptyMessage}
                    maxHeight={maxHeight}
                    topRow={topRow}
                    listRow={listRow}
                    rowActions={rowActions}
                    fullBleedOnPhone={fullBleedOnPhone}
                    {...dayHeaders}
                />
            ) : (
                <DataTable
                    table={table}
                    columns={columns}
                    emptyMessage={emptyMessage}
                    renderRow={renderRow}
                    maxHeight={maxHeight}
                    topRow={topRow}
                    {...dayHeaders}
                />
            )}
        </div>
    );
}

function TransactionCompactList({
    table,
    sorting,
    rowSelection,
    selectionActive,
    selectionMode,
    emptyMessage,
    maxHeight,
    topRow,
    listRow,
    rowActions,
    fullBleedOnPhone,
    groupRowsBy,
    renderGroupHeader,
}: Pick<
    ResponsiveTransactionTableProps,
    | 'table'
    | 'sorting'
    | 'rowSelection'
    | 'selectionMode'
    | 'emptyMessage'
    | 'maxHeight'
    | 'topRow'
    | 'listRow'
    | 'rowActions'
    | 'fullBleedOnPhone'
> & {
    selectionActive: boolean;
    groupRowsBy?: (transaction: ServerTransaction) => string;
    renderGroupHeader: (
        group: DataTableRowGroup<ServerTransaction>,
        measureProps: DataTableMeasureProps,
    ) => React.ReactNode;
}) {
    // Reads the table's rows, which change inside an object that stays the
    // same, so memoized by the compiler they would never update.
    'use no memo';

    const scrollRef = useRef<HTMLDivElement>(null);
    const rows = table.getRowModel().rows;
    const { items, rowVirtualizer, virtualRows, paddingTop, paddingBottom } =
        useGroupedRowVirtualizer({
            rows,
            groupRowsBy,
            scrollRef,
            // Without a height of its own the list grows with the page, which
            // scrolls instead.
            scrollsWithWindow: !maxHeight,
            estimateSize: { group: 33, row: 68 },
        });

    return (
        <div
            className="flex flex-col gap-2"
            data-testid="transaction-compact-list"
        >
            <div className="flex justify-end">
                <TransactionSortMenu table={table} sorting={sorting} />
            </div>
            {topRow && (
                <div
                    className={cn(
                        'overflow-hidden rounded-md border',
                        fullBleedOnPhone &&
                            'max-md:-mx-6 max-md:rounded-none max-md:border-x-0',
                    )}
                >
                    {topRow}
                </div>
            )}
            <div
                ref={scrollRef}
                className={cn(
                    'overflow-hidden rounded-md border',
                    fullBleedOnPhone
                        ? // Edge to edge on a phone: a hairline above, and the
                          // last row keeps its own below, since no frame does.
                          'max-md:-mx-6 max-md:rounded-none max-md:border-x-0 max-md:border-b-0 md:[&>div>div:last-child_[data-testid=transaction-list-row]]:border-b-0'
                        : // Each row draws its hairline below; the last one
                          // would sit on the frame's border and double it.
                          '[&>div>div:last-child_[data-testid=transaction-list-row]]:border-b-0',
                )}
                data-full-bleed={fullBleedOnPhone || undefined}
                style={maxHeight ? { maxHeight, overflowY: 'auto' } : undefined}
            >
                {rows.length === 0 ? (
                    <div className="p-6 text-center text-sm text-muted-foreground">
                        {emptyMessage}
                    </div>
                ) : (
                    <div style={{ paddingTop, paddingBottom }}>
                        {virtualRows.map((virtualRow) => {
                            const item = items[virtualRow.index];
                            const measureProps = {
                                ref: rowVirtualizer.measureElement,
                                'data-index': virtualRow.index,
                            };

                            if (item.type === 'group') {
                                return (
                                    <Fragment key={virtualRow.key}>
                                        {renderGroupHeader(
                                            item.group,
                                            measureProps,
                                        )}
                                    </Fragment>
                                );
                            }

                            return (
                                <div key={virtualRow.key} {...measureProps}>
                                    <TransactionListRowItem
                                        transaction={item.row.original}
                                        selected={!!rowSelection[item.row.id]}
                                        selectionActive={selectionActive}
                                        selectionMode={selectionMode}
                                        listRow={listRow}
                                        rowActions={rowActions}
                                    />
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * A row wired to the page: its circle and, once rows are being picked, any
 * tap toggle it; otherwise a tap edits it. A long press selects it on the
 * phone, and a right click opens the row menu elsewhere.
 */
function TransactionListRowItem({
    transaction,
    selected,
    selectionActive,
    selectionMode,
    listRow,
    rowActions,
}: {
    transaction: ServerTransaction;
    selected: boolean;
    selectionActive: boolean;
    selectionMode: RowSelectionMode;
    listRow: TransactionListRowOptions;
    rowActions: TransactionRowActionHandlers;
}) {
    const longPress = useLongPressSelect(transaction.id, selectionMode);

    return (
        <ContextMenu>
            {/* A long press on the phone selects the row, not this menu. */}
            <ContextMenuTrigger asChild disabled={selectionMode.isMobile}>
                <TransactionListRow
                    transaction={transaction}
                    categories={listRow.categories}
                    labels={listRow.labels}
                    selected={selected}
                    selectionActive={selectionActive}
                    onToggleSelect={() =>
                        selectionMode.toggleRow(transaction.id)
                    }
                    onOpen={rowActions.onEdit}
                    onUnsplit={rowActions.onUnsplit}
                    isCategorizing={listRow.categorizingIds?.has(
                        transaction.id,
                    )}
                    hiddenLabelId={listRow.hiddenLabelId}
                    statementPayment={listRow.statementPayments?.get(
                        transaction.id,
                    )}
                    {...(selectionMode.isMobile && {
                        ...longPress.handlers,
                        className: '[-webkit-touch-callout:none]',
                        // The lift of a long press is not a tap on the row.
                        onClickCapture: (event: MouseEvent) => {
                            if (longPress.consumeLongPress()) {
                                event.stopPropagation();
                            }
                        },
                    })}
                />
            </ContextMenuTrigger>
            <TransactionRowContextMenuContent
                transaction={transaction}
                {...rowActions}
            />
        </ContextMenu>
    );
}
