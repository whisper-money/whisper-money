import { Fragment, useRef } from 'react';
import {
    ColumnDef,
    flexRender,
    Row,
    Table as TableType,
} from '@tanstack/react-table';
import {
    useVirtualizer,
    VirtualItem,
    Virtualizer,
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

interface DataTableProps<TData, TValue> {
    table: TableType<TData>;
    columns: ColumnDef<TData, TValue>[];
    emptyMessage?: string;
    renderRow?: (
        row: Row<TData>,
        virtualRow: VirtualItem,
        rowVirtualizer: Virtualizer<HTMLDivElement, Element>,
    ) => React.ReactNode;
    renderDateHeader?: (
        date: string,
        colSpan: number,
    ) => React.ReactNode;
    getRowDate?: (row: TData) => string;
    maxHeight?: number;
}

export function DataTable<TData, TValue>({
    table,
    columns,
    emptyMessage = 'No results found.',
    renderRow,
    renderDateHeader,
    getRowDate,
    maxHeight,
}: DataTableProps<TData, TValue>) {
    const tableContainerRef = useRef<HTMLDivElement>(null);
    const rows = table.getRowModel().rows;

    const rowVirtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => tableContainerRef.current,
        estimateSize: () => 72,
        overscan: 12,
    });

    const virtualRows = rowVirtualizer.getVirtualItems();
    const totalSize = rowVirtualizer.getTotalSize();
    const paddingTop = virtualRows.length > 0 ? virtualRows[0].start : 0;
    const paddingBottom =
        virtualRows.length > 0
            ? totalSize - virtualRows[virtualRows.length - 1].end
            : 0;
    const visibleColumnCount =
        table.getVisibleLeafColumns().length || columns.length || 1;

    const showDateHeaders =
        renderDateHeader &&
        getRowDate &&
        table.getState().columnVisibility.transaction_date !== false;

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
                                    const row = rows[virtualRow.index];
                                    const prevRow =
                                        virtualRow.index > 0
                                            ? rows[virtualRow.index - 1]
                                            : null;

                                    const currentDate =
                                        showDateHeaders && getRowDate
                                            ? getRowDate(row.original)
                                            : null;
                                    const prevDate =
                                        showDateHeaders &&
                                            getRowDate &&
                                            prevRow
                                            ? getRowDate(prevRow.original)
                                            : null;

                                    const showDateHeader =
                                        showDateHeaders &&
                                        currentDate &&
                                        currentDate !== prevDate;

                                    if (renderRow) {
                                        return (
                                            <Fragment key={row.id}>
                                                {showDateHeader &&
                                                    renderDateHeader &&
                                                    renderDateHeader(
                                                        currentDate,
                                                        visibleColumnCount,
                                                    )}
                                                {renderRow(
                                                    row,
                                                    virtualRow,
                                                    rowVirtualizer,
                                                )}
                                            </Fragment>
                                        );
                                    }

                                    return (
                                        <Fragment key={row.id}>
                                            {showDateHeader &&
                                                renderDateHeader &&
                                                renderDateHeader(
                                                    currentDate,
                                                    visibleColumnCount,
                                                )}
                                            <TableRow
                                                ref={
                                                    rowVirtualizer.measureElement
                                                }
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
                                        </Fragment>
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
                                    className="h-24 text-center"
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
