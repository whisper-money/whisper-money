import { useTransactionDayHeaders } from '@/components/transactions/transaction-day-header';
import { TransactionListRow } from '@/components/transactions/transaction-list-row';
import { TransactionRowContextMenuContent } from '@/components/transactions/transaction-row-context-menu';
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import {
    DataTable,
    type DataTableMeasureProps,
    type DataTableRowGroup,
    useGroupedRowVirtualizer,
} from '@/components/ui/data-table';
import { useElementWidth } from '@/hooks/use-element-width';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    type RowSelectionMode,
    useLongPressSelect,
} from '@/hooks/use-mobile-selection-mode';
import { PHONE_FRAME, PHONE_INSET } from '@/lib/compact-list-style';
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

/**
 * Each row draws its hairline below; inside a frame the last one would sit on
 * the frame's border and double it. On a phone the frame has no bottom border,
 * so the last row keeps its own: the md-only twin.
 */
const LAST_ROW_ON_FRAME =
    '[&>div>div:last-child_[data-testid=transaction-list-row]]:border-b-0';
const MD_LAST_ROW_ON_FRAME =
    'md:[&>div>div:last-child_[data-testid=transaction-list-row]]:border-b-0';

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
    rowSelection: RowSelectionState;
    /** What each day header says about its whole day, loaded rows or not. */
    daySummaryOf: DaySummaryOf;
    selectionMode: RowSelectionMode;
    listRow: TransactionListRowOptions;
    rowActions: TransactionRowActionHandlers;
    /**
     * Keep the compact list's plain frame on a phone, for a list inside a
     * card. Otherwise it sits 16px from the screen's edges there, open at the
     * bottom (see `PHONE_FRAME`).
     */
    framedOnPhone?: boolean;
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
    rowSelection,
    daySummaryOf,
    selectionMode,
    listRow,
    rowActions,
    framedOnPhone = false,
}: ResponsiveTransactionTableProps) {
    const [containerRef, width] = useElementWidth<HTMLDivElement>();
    const layout =
        width !== null && width < LIST_LAYOUT_BELOW_PX ? 'list' : 'table';
    const selectionActive = Object.keys(rowSelection).length > 0;

    const dayHeaders = useTransactionDayHeaders({
        table,
        rowSelection,
        daySummaryOf,
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
                    rowSelection={rowSelection}
                    selectionActive={selectionActive}
                    selectionMode={selectionMode}
                    emptyMessage={emptyMessage}
                    maxHeight={maxHeight}
                    topRow={topRow}
                    listRow={listRow}
                    rowActions={rowActions}
                    framedOnPhone={framedOnPhone}
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
    rowSelection,
    selectionActive,
    selectionMode,
    emptyMessage,
    maxHeight,
    topRow,
    listRow,
    rowActions,
    framedOnPhone,
    groupRowsBy,
    renderGroupHeader,
}: Pick<
    ResponsiveTransactionTableProps,
    | 'table'
    | 'rowSelection'
    | 'selectionMode'
    | 'emptyMessage'
    | 'maxHeight'
    | 'topRow'
    | 'listRow'
    | 'rowActions'
    | 'framedOnPhone'
> & {
    selectionActive: boolean;
    groupRowsBy: (transaction: ServerTransaction) => string;
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
    // On a phone a box of its own height would leave only a 16px gutter to
    // scroll the page by: nearly every swipe would scroll the list instead.
    // There the list grows with the page, as /transactions does everywhere.
    const isPhone = useIsMobile();
    const boxHeight = isPhone && !framedOnPhone ? undefined : maxHeight;
    const { items, rowVirtualizer, virtualRows, paddingTop, paddingBottom } =
        useGroupedRowVirtualizer({
            rows,
            groupRowsBy,
            scrollRef,
            // Without a height of its own the list grows with the page, which
            // scrolls instead.
            scrollsWithWindow: !boxHeight,
            estimateSize: { group: 33, row: 68 },
        });

    return (
        <div
            className="flex flex-col gap-2"
            data-testid="transaction-compact-list"
        >
            {topRow && (
                <div
                    className={cn(
                        'overflow-hidden rounded-md border',
                        !framedOnPhone && PHONE_INSET,
                    )}
                >
                    {topRow}
                </div>
            )}
            <div
                ref={scrollRef}
                className={cn(
                    'overflow-hidden rounded-md border',
                    framedOnPhone
                        ? LAST_ROW_ON_FRAME
                        : [PHONE_FRAME, MD_LAST_ROW_ON_FRAME],
                )}
                data-testid="transaction-compact-list-rows"
                style={
                    boxHeight
                        ? { maxHeight: boxHeight, overflowY: 'auto' }
                        : undefined
                }
            >
                {rows.length === 0 ? (
                    <div
                        className={cn(
                            'p-6 text-center text-sm text-muted-foreground',
                            // No row draws the closing hairline here.
                            !framedOnPhone && 'max-md:border-b',
                        )}
                    >
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
