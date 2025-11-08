import { useRef } from 'react';
import {
    ColumnDef,
    flexRender,
    Table as TableType,
} from '@tanstack/react-table';
import { useVirtualizer } from '@tanstack/react-virtual';

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface DataTableProps<TData, TValue> {
    table: TableType<TData>;
    columns: ColumnDef<TData, TValue>[];
    emptyMessage?: string;
}

export function DataTable<TData, TValue>({
    table,
    columns,
    emptyMessage = 'No results found.',
}: DataTableProps<TData, TValue>) {
    const tableContainerRef = useRef<HTMLDivElement>(null);
    const rows = table.getRowModel().rows;

    const rowVirtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => tableContainerRef.current,
        estimateSize: () => 56,
        overscan: 12,
    });

    const virtualRows = rowVirtualizer.getVirtualItems();
    const totalSize = rowVirtualizer.getTotalSize();
    const paddingTop =
        virtualRows.length > 0 ? virtualRows[0].start : 0;
    const paddingBottom =
        virtualRows.length > 0
            ? totalSize - virtualRows[virtualRows.length - 1].end
            : 0;

    return (
        <div className="overflow-hidden rounded-md border">
            <div
                ref={tableContainerRef}
            >
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    return (
                                        <TableHead key={header.id}>
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
                                            colSpan={columns.length}
                                            style={{ height: paddingTop }}
                                        />
                                    </TableRow>
                                )}
                                {virtualRows.map((virtualRow) => {
                                    const row = rows[virtualRow.index];
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
                                                .map((cell) => (
                                                    <TableCell key={cell.id}>
                                                        {flexRender(
                                                            cell.column.columnDef
                                                                .cell,
                                                            cell.getContext(),
                                                        )}
                                                    </TableCell>
                                                ))}
                                        </TableRow>
                                    );
                                })}
                                {paddingBottom > 0 && (
                                    <TableRow className="border-none hover:bg-transparent">
                                        <TableCell
                                            colSpan={columns.length}
                                            style={{ height: paddingBottom }}
                                        />
                                    </TableRow>
                                )}
                            </>
                        ) : (
                            <TableRow>
                                <TableCell
                                    colSpan={columns.length}
                                    className="h-24 text-center"
                                >
                                    {emptyMessage}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

