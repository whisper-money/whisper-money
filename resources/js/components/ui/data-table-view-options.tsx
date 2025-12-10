import { useEffect, useState } from 'react';
import { type Table as TableType } from '@tanstack/react-table';
import { SlidersHorizontal } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

interface DataTableViewOptionsProps<TData> {
    table: TableType<TData>;
    hideColumnTextOnMobile?: boolean
}

export function DataTableViewOptions<TData>({
    table,
    hideColumnTextOnMobile = true,
}: DataTableViewOptionsProps<TData>) {
    const columns = table
        .getAllLeafColumns()
        .filter((column) => column.getCanHide());

    const visibilityStates = columns.map((col) => col.getIsVisible()).join(',');
    const [, forceUpdate] = useState(0);

    useEffect(() => {
        forceUpdate((prev) => prev + 1);
    }, [visibilityStates]);

    if (columns.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" className='flex gap-2'>
                    <span className={cn({ 'hidden sm:inline': hideColumnTextOnMobile })}>Columns</span>
                    <SlidersHorizontal className="h-4 w-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                {columns.map((column) => {
                    const label: string =
                        typeof column.columnDef.header === 'string'
                            ? column.columnDef.header
                            : ((column.columnDef.meta as Record<string, unknown>)?.label as string) ?? column.id.replace(/_/g, ' ');

                    return (
                        <DropdownMenuCheckboxItem
                            key={column.id}
                            className="capitalize"
                            checked={column.getIsVisible()}
                            onCheckedChange={(value) =>
                                column.toggleVisibility(!!value)
                            }
                        >
                            {label}
                        </DropdownMenuCheckboxItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
