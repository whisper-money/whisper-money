import { TransactionRowContextMenuContent } from '@/components/transactions/transaction-row-context-menu';
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { type TransactionRowActionHandlers } from '@/lib/transaction-row-actions';
import { cn } from '@/lib/utils';
import { type ServerTransaction } from '@/types/transaction';
import { flexRender, type Cell, type Row } from '@tanstack/react-table';
import { type VirtualItem, type Virtualizer } from '@tanstack/react-virtual';
import { useState, type CSSProperties, type MouseEvent } from 'react';

interface ColumnMeta {
    isVirtual?: boolean;
    cellClassName?: string;
    cellStyle?: CSSProperties;
}

const INTERACTIVE_TARGETS =
    'button, [role="checkbox"], [role="combobox"], [role="menuitem"], [role="option"], [cmdk-item], a, input, select, textarea, [data-radix-popper-content-wrapper]';

interface TransactionTableRowProps extends TransactionRowActionHandlers {
    row: Row<ServerTransaction>;
    virtualRow: VirtualItem;
    rowVirtualizer: Virtualizer<HTMLDivElement, Element>;
    /** Marks a row that arrived since the last visit. */
    isNew?: boolean;
    /** Added to every cell of the row. */
    cellClassName?: string;
}

/**
 * One transaction in the desktop table: a click anywhere but a control opens
 * the edit dialog, and a right click opens the row menu.
 */
export function TransactionTableRow({
    row,
    virtualRow,
    rowVirtualizer,
    isNew = false,
    cellClassName,
    ...handlers
}: TransactionTableRowProps) {
    const transaction = row.original;
    const [contextMenuOpen, setContextMenuOpen] = useState(false);

    const handleRowClick = (event: MouseEvent<HTMLTableRowElement>) => {
        const target = event.target as HTMLElement;
        if (target.closest(INTERACTIVE_TARGETS)) {
            return;
        }
        handlers.onEdit(transaction);
    };

    return (
        <ContextMenu onOpenChange={setContextMenuOpen}>
            <ContextMenuTrigger asChild>
                <TableRow
                    ref={rowVirtualizer.measureElement}
                    data-state={
                        (row.getIsSelected() || contextMenuOpen) && 'selected'
                    }
                    data-index={virtualRow.index}
                    className={cn(
                        'cursor-pointer',
                        isNew && 'bg-blue-500/5 dark:bg-blue-500/10',
                    )}
                    onClick={handleRowClick}
                >
                    {row
                        .getVisibleCells()
                        .filter(
                            (cell: Cell<ServerTransaction, unknown>) =>
                                !(cell.column.columnDef.meta as ColumnMeta)
                                    ?.isVirtual,
                        )
                        .map((cell, cellIndex) => {
                            const meta = cell.column.columnDef.meta as
                                | ColumnMeta
                                | undefined;
                            return (
                                <TableCell
                                    key={cell.id}
                                    className={cn(
                                        meta?.cellClassName,
                                        cellClassName,
                                        isNew &&
                                            cellIndex === 0 &&
                                            'border-l-2 border-l-blue-500',
                                    )}
                                    style={meta?.cellStyle}
                                >
                                    {flexRender(
                                        cell.column.columnDef.cell,
                                        cell.getContext(),
                                    )}
                                </TableCell>
                            );
                        })}
                </TableRow>
            </ContextMenuTrigger>
            <TransactionRowContextMenuContent
                transaction={transaction}
                {...handlers}
            />
        </ContextMenu>
    );
}
