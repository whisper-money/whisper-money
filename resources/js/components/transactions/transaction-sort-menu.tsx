import { SORTABLE_TRANSACTION_COLUMNS } from '@/components/transactions/transaction-columns';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { type SortingState, type Table } from '@tanstack/react-table';
import { ArrowDown, ArrowUp } from 'lucide-react';

/**
 * The sort a card layout offers in place of the table's clickable headings:
 * the same columns, set through the table so each page sorts as it does from
 * a heading.
 */
export function TransactionSortMenu({
    table,
    sorting,
}: {
    table: Table<ServerTransaction>;
    /** Handed in as state: read off the table, the compiler would memoize it. */
    sorting: SortingState;
}) {
    const columns = table
        .getAllLeafColumns()
        .filter((column) =>
            (SORTABLE_TRANSACTION_COLUMNS as readonly string[]).includes(
                column.id,
            ),
        );
    const current = sorting[0];
    const currentColumn = columns.find((column) => column.id === current?.id);

    if (columns.length === 0) {
        return null;
    }

    const labelOf = (column: (typeof columns)[number]) =>
        (column.columnDef.meta as { label?: string } | undefined)?.label ??
        column.id;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 gap-1 px-2 text-muted-foreground"
                    data-testid="transaction-sort-menu"
                >
                    {__('Sort by')}
                    <span className="font-medium text-foreground">
                        {currentColumn ? labelOf(currentColumn) : '—'}
                    </span>
                    {current?.desc ? (
                        <ArrowDown className="size-3.5" />
                    ) : (
                        <ArrowUp className="size-3.5" />
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel>{__('Sort by')}</DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={current?.id}
                    onValueChange={(id) =>
                        // Dates read newest first, names from A.
                        table.setSorting([
                            { id, desc: id === 'transaction_date' },
                        ])
                    }
                >
                    {columns.map((column) => (
                        <DropdownMenuRadioItem
                            key={column.id}
                            value={column.id}
                        >
                            {labelOf(column)}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                {current && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuRadioGroup
                            value={current.desc ? 'desc' : 'asc'}
                            onValueChange={(direction) =>
                                table.setSorting([
                                    {
                                        id: current.id,
                                        desc: direction === 'desc',
                                    },
                                ])
                            }
                        >
                            <DropdownMenuRadioItem value="desc">
                                {__('Descending')}
                            </DropdownMenuRadioItem>
                            <DropdownMenuRadioItem value="asc">
                                {__('Ascending')}
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
