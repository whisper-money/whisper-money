interface DataTablePaginationProps {
    rowCountLabel?: string;
    displayedCount?: number;
    total?: number;
    children?: React.ReactNode;
}

export function DataTablePagination({
    displayedCount = undefined,
    total = undefined,
    rowCountLabel = 'row(s) total',
    children,
}: DataTablePaginationProps) {
    return (
        <div className="flex items-center justify-end space-x-2">
            {displayedCount && total && (
                <div className="text-muted-foreground flex-1 text-sm">
                    {Math.min(total, displayedCount)} of {total} {rowCountLabel}
                </div>
            )}
            {displayedCount && total === undefined && (
                <div className="text-muted-foreground flex-1 text-sm">
                    {displayedCount} {rowCountLabel}
                </div>
            )}
            {children}
        </div>
    );
}

