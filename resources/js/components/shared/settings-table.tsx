import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    flexRender,
    type Row,
    type Table as TableType,
} from '@tanstack/react-table';
import { type ReactNode } from 'react';

/**
 * The bordered table the settings pages list their records in. Deliberately not
 * the virtualized DataTable: these lists are short, and each page renders its
 * own row component (they carry a per-row context menu).
 *
 * Tables rendered here must set `getRowId` to the record id. Row keys fall back
 * to the row index otherwise, and because these lists reorder on rename, React
 * would hand a mounted row (and the edit dialog inside it) a different record.
 */
export function SettingsTable<TData>({
    table,
    emptyMessage,
    renderRow,
}: {
    table: TableType<TData>;
    emptyMessage: string;
    renderRow: (row: Row<TData>) => ReactNode;
}) {
    const rows = table.getRowModel().rows;

    return (
        // Scrolls rather than clips: the accounts table is wider than a phone.
        <div className="overflow-x-auto rounded-md border">
            <Table>
                <TableHeader>
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow key={headerGroup.id}>
                            {headerGroup.headers.map((header) => (
                                <TableHead key={header.id}>
                                    {header.isPlaceholder
                                        ? null
                                        : flexRender(
                                              header.column.columnDef.header,
                                              header.getContext(),
                                          )}
                                </TableHead>
                            ))}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {rows.length ? (
                        rows.map(renderRow)
                    ) : (
                        <TableRow>
                            <TableCell
                                colSpan={table.getAllColumns().length}
                                className="h-24 text-center align-middle"
                            >
                                {emptyMessage}
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </div>
    );
}
