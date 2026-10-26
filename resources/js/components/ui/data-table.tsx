import {
    Fragment,
    type RefObject,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    ColumnDef,
    flexRender,
    Row,
    Table as TableType,
} from '@tanstack/react-table';
import {
    observeWindowOffset,
    observeWindowRect,
    useVirtualizer,
    VirtualItem,
    Virtualizer,
    type VirtualizerOptions,
    windowScroll,
} from '@tanstack/react-virtual';

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface ColumnMeta {
    isVirtual?: boolean;
    cellClassName?: string;
    cellStyle?: React.CSSProperties;
}

/** A run of consecutive rows that share a group key, e.g. a day. */
export interface DataTableRowGroup<TData> {
    key: string;
    rows: Row<TData>[];
}

/**
 * What a group header spreads on its `<tr>`, so the virtualizer measures it
 * like any row instead of guessing its height.
 */
export interface DataTableMeasureProps {
    ref: (element: Element | null) => void;
    'data-index': number;
}

export type DataTableItem<TData> =
    | { type: 'row'; row: Row<TData> }
    | { type: 'group'; group: DataTableRowGroup<TData> };

function buildItems<TData>(
    rows: Row<TData>[],
    groupRowsBy?: (row: TData) => string,
): DataTableItem<TData>[] {
    if (!groupRowsBy) {
        return rows.map((row) => ({ type: 'row', row }));
    }

    const items: DataTableItem<TData>[] = [];
    const groups: DataTableRowGroup<TData>[] = [];

    for (const row of rows) {
        const key = groupRowsBy(row.original);
        let group = groups.at(-1);

        if (group?.key !== key) {
            group = { key, rows: [] };
            groups.push(group);
            items.push({ type: 'group', group });
        }

        group.rows.push(row);
        items.push({ type: 'row', row });
    }

    return items;
}

/**
 * Virtualizes rows and their group headers as one list, so a header scrolls
 * with its rows and is measured like any of them. Shared by every layout that
 * draws a table's rows, whatever markup each item becomes.
 *
 * With `scrollsWithWindow` the page scrolls rather than `scrollRef`, which is
 * then only where the list starts.
 */
export function useGroupedRowVirtualizer<TData>({
    rows,
    groupRowsBy,
    scrollRef,
    scrollsWithWindow = false,
    estimateSize,
}: {
    rows: Row<TData>[];
    groupRowsBy?: (row: TData) => string;
    scrollRef: RefObject<HTMLDivElement | null>;
    scrollsWithWindow?: boolean;
    /** First guesses in px, corrected once each item is measured. */
    estimateSize: { group: number; row: number };
}) {
    // The compiler would memoize what is read off the virtualizer, a stable
    // object that changes under it, and the list would stop following scroll.
    'use no memo';

    const items = useMemo(
        () => buildItems(rows, groupRowsBy),
        [rows, groupRowsBy],
    );
    const [scrollMargin, setScrollMargin] = useState(0);

    // Whatever sits above the list can grow, so where it starts is re-read
    // after every render rather than once; it only sets state on a change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useLayoutEffect(() => {
        if (!scrollsWithWindow || !scrollRef.current) {
            return;
        }

        const top = Math.round(
            scrollRef.current.getBoundingClientRect().top + window.scrollY,
        );

        if (top !== scrollMargin) {
            setScrollMargin(top);
        }
    });

    const windowScrolling = {
        getScrollElement: () => window,
        observeElementRect: observeWindowRect,
        observeElementOffset: observeWindowOffset,
        scrollToFn: windowScroll,
        initialOffset: () => window.scrollY,
        scrollMargin,
    } as unknown as Partial<VirtualizerOptions<HTMLDivElement, Element>>;

    const rowVirtualizer = useVirtualizer({
        count: items.length,
        getScrollElement: () => scrollRef.current,
        estimateSize: (index) =>
            items[index]?.type === 'group'
                ? estimateSize.group
                : estimateSize.row,
        getItemKey: (index) => {
            const item = items[index];

            return item.type === 'group'
                ? `group:${item.group.key}:${item.group.rows[0].id}`
                : item.row.id;
        },
        overscan: 12,
        ...(scrollsWithWindow && windowScrolling),
    });

    const virtualRows = rowVirtualizer.getVirtualItems();
    const totalSize = rowVirtualizer.getTotalSize();
    const paddingTop =
        virtualRows.length > 0 ? virtualRows[0].start - scrollMargin : 0;
    // A list scrolled out of the window shows no item, yet keeps its height.
    const paddingBottom =
        virtualRows.length > 0
            ? totalSize - (virtualRows[virtualRows.length - 1].end - scrollMargin)
            : totalSize;

    return { items, rowVirtualizer, virtualRows, paddingTop, paddingBottom };
}

interface DataTableProps<TData, TValue> {
    table: TableType<TData>;
    columns: ColumnDef<TData, TValue>[];
    emptyMessage?: string;
    renderRow?: (
        row: Row<TData>,
        virtualRow: VirtualItem,
        rowVirtualizer: Virtualizer<HTMLDivElement, Element>,
    ) => React.ReactNode;
    /** Groups consecutive rows under a header; leave it out for a flat table. */
    groupRowsBy?: (row: TData) => string;
    renderGroupHeader?: (
        group: DataTableRowGroup<TData>,
        measureProps: DataTableMeasureProps,
    ) => React.ReactNode;
    maxHeight?: number;
    /** Static content pinned as the first row of the table body, spanning all columns. */
    topRow?: React.ReactNode;
}

export function DataTable<TData, TValue>({
    table,
    columns,
    emptyMessage = 'No results found.',
    renderRow,
    groupRowsBy,
    renderGroupHeader,
    maxHeight,
    topRow,
}: DataTableProps<TData, TValue>) {
    // Reads the table's rows, which change inside an object that stays the
    // same, so memoized by the compiler they would never update.
    'use no memo';

    const tableContainerRef = useRef<HTMLDivElement>(null);
    const rows = table.getRowModel().rows;
    const { items, rowVirtualizer, virtualRows, paddingTop, paddingBottom } =
        useGroupedRowVirtualizer({
            rows,
            groupRowsBy: renderGroupHeader ? groupRowsBy : undefined,
            scrollRef: tableContainerRef,
            estimateSize: { group: 44, row: 72 },
        });
    const visibleColumnCount =
        table.getVisibleLeafColumns().length || columns.length || 1;

    return (
        <div className="overflow-hidden rounded-md border">
            <div
                ref={tableContainerRef}
                style={
                    maxHeight ? { maxHeight, overflowY: 'auto' } : undefined
                }
            >
                <Table>
                    <TableHeader
                        className={
                            maxHeight
                                ? 'sticky top-0 z-10 bg-background'
                                : undefined
                        }
                    >
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    const meta = header.column.columnDef
                                        .meta as ColumnMeta | undefined;
                                    if (meta?.isVirtual) {
                                        return null;
                                    }
                                    return (
                                        <TableHead
                                            key={header.id}
                                            className={meta?.cellClassName}
                                            style={meta?.cellStyle}
                                        >
                                            {header.isPlaceholder
                                                ? null
                                                : flexRender(
                                                    header.column.columnDef
                                                        .header,
                                                    header.getContext(),
                                                )}
                                        </TableHead>
                                    );
                                })}
                            </TableRow>
                        ))}
                    </TableHeader>
                    <TableBody>
                        {topRow && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={visibleColumnCount}
                                    className="p-0 whitespace-normal"
                                >
                                    {topRow}
                                </TableCell>
                            </TableRow>
                        )}
                        {rows.length ? (
                            <>
                                {paddingTop > 0 && (
                                    <TableRow className="border-none hover:bg-transparent">
                                        <TableCell
                                            colSpan={visibleColumnCount}
                                            style={{ height: paddingTop }}
                                        />
                                    </TableRow>
                                )}
                                {virtualRows.map((virtualRow) => {
                                    const item = items[virtualRow.index];

                                    if (item.type === 'group') {
                                        // The key sits on the fragment: spread
                                        // through the measure props, React warns.
                                        return (
                                            <Fragment key={virtualRow.key}>
                                                {renderGroupHeader?.(
                                                    item.group,
                                                    {
                                                        ref: rowVirtualizer.measureElement,
                                                        'data-index':
                                                            virtualRow.index,
                                                    },
                                                )}
                                            </Fragment>
                                        );
                                    }

                                    const row = item.row;

                                    if (renderRow) {
                                        return (
                                            <Fragment key={row.id}>
                                                {renderRow(
                                                    row,
                                                    virtualRow,
                                                    rowVirtualizer,
                                                )}
                                            </Fragment>
                                        );
                                    }

                                    return (
                                        <TableRow
                                            key={row.id}
                                            ref={rowVirtualizer.measureElement}
                                            data-state={
                                                row.getIsSelected() &&
                                                'selected'
                                            }
                                            data-index={virtualRow.index}
                                        >
                                            {row
                                                .getVisibleCells()
                                                .map((cell) => {
                                                    const meta = cell.column
                                                        .columnDef.meta as
                                                        | ColumnMeta
                                                        | undefined;
                                                    if (meta?.isVirtual) {
                                                        return null;
                                                    }
                                                    return (
                                                        <TableCell
                                                            key={cell.id}
                                                            className={meta?.cellClassName}
                                                            style={meta?.cellStyle}
                                                        >
                                                            {flexRender(
                                                                cell.column
                                                                    .columnDef
                                                                    .cell,
                                                                cell.getContext(),
                                                            )}
                                                        </TableCell>
                                                    );
                                                })}
                                        </TableRow>
                                    );
                                })}
                                {paddingBottom > 0 && (
                                    <TableRow className="border-none hover:bg-transparent">
                                        <TableCell
                                            colSpan={visibleColumnCount}
                                            style={{ height: paddingBottom }}
                                        />
                                    </TableRow>
                                )}
                            </>
                        ) : (
                            <TableRow>
                                <TableCell
                                    colSpan={visibleColumnCount}
                                    className="h-24 text-center align-middle"
                                    style={
                                        maxHeight
                                            ? {
                                                  height: 80,
                                              }
                                            : undefined
                                    }
                                >
                                    {emptyMessage}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div >
    );
}
