import {
    type ColumnDef,
    type ColumnFiltersState,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    type SortingState,
    useReactTable,
    type VisibilityState,
} from '@tanstack/react-table';
import { useState } from 'react';

/**
 * A sortable, filterable table over a list the server already sent whole — the
 * shape every settings page needs. Sorting, filtering and column visibility are
 * client-side, so no page has to wire the same four pieces of state again.
 */
export function useSettingsTable<TData>(
    data: TData[],
    columns: ColumnDef<TData>[],
    initialSorting: SortingState = [],
) {
    const [sorting, setSorting] = useState<SortingState>(initialSorting);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    return useReactTable({
        data,
        columns,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        state: {
            sorting,
            columnFilters,
            columnVisibility,
        },
    });
}
