import { Table as TableType } from '@tanstack/react-table';

import { Button } from '@/components/ui/button';
import { RefreshCw } from 'lucide-react';

interface DataTablePaginationProps<TData> {
    table: TableType<TData>;
    rowCountLabel?: string;
    displayedCount?: number;
    total?: number;
}

export function DataTablePagination<TData>({
    table,
    displayedCount = undefined,
    total = undefined,
    rowCountLabel = 'row(s) total',
}: DataTablePaginationProps<TData>) {
    return (
        <div className="flex px-5 items-center justify-end space-x-2">
            {displayedCount && total && (
                <div className="text-muted-foreground flex-1 text-sm">
                    {displayedCount} of {total} {rowCountLabel}
                </div>
            )}
            {displayedCount && total === undefined && (
                <div className="text-muted-foreground flex-1 text-sm">
                    {displayedCount} {rowCountLabel}
                </div>
            )}
            {((displayedCount ?? 0) < (total ?? 0)) && <div className="space-x-2">
                <Button
                    variant="ghost"
                    size="sm"
                    disabled
                >
                    Loading <RefreshCw className="ml-2 h-4 w-4 animate-spin" />
                </Button>
            </div>}
        </div>
    );
}

