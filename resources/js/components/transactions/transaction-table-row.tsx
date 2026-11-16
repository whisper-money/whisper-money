import { TransactionRowContextMenuContent } from '@/components/transactions/transaction-row-context-menu';
import { ContextMenu, ContextMenuTrigger } from '@/components/ui/context-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { type TransactionRowActionHandlers } from '@/lib/transaction-row-actions';
import { cn } from '@/lib/utils';
import { type DecryptedTransaction } from '@/types/transaction';
import { flexRender, type Cell, type Row } from '@tanstack/react-table';
import { type VirtualItem, type Virtualizer } from '@tanstack/react-virtual';
import {
    useState,
    type ComponentProps,
    type CSSProperties,
    type MouseEvent,
} from 'react';

interface ColumnMeta {
    isVirtual?: boolean;
    cellClassName?: string;
    cellStyle?: CSSProperties;
}

const INTERACTIVE_TARGETS =
    'button, [role="checkbox"], [role="combobox"], [role="menuitem"], [role="option"], [cmdk-item], a, input, select, textarea, [data-radix-popper-content-wrapper]';

interface TransactionTableRowProps extends TransactionRowActionHandlers {
    row: Row<DecryptedTransaction>;
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
    const [contextMenuOpen, setContextMenuOpen] = useState(false);

    return (
        <ContextMenu onOpenChange={setContextMenuOpen}>
            <ContextMenuTrigger asChild>
                <TransactionTableRowCells
                    row={row}
                    virtualRow={virtualRow}
                    rowVirtualizer={rowVirtualizer}
                    isNew={isNew}
                    cellClassName={cellClassName}
                    highlighted={contextMenuOpen}
                    onOpen={handlers.onEdit}
                />
            </ContextMenuTrigger>
            <TransactionRowContextMenuContent
                transaction={row.original}
                {...handlers}
            />
        </ContextMenu>
    );
}

/**
 * The row itself, without a menu: its cells, and a click anywhere but a
 * control hands the transaction to `onOpen`. Without `onOpen` it is only read.
 */
export function TransactionTableRowCells({
    row,
    virtualRow,
    rowVirtualizer,
    isNew = false,
    cellClassName,
    highlighted = false,
    onOpen,
    ...props
}: Omit<TransactionTableRowProps, keyof TransactionRowActionHandlers> &
    Omit<ComponentProps<typeof TableRow>, 'onClick'> & {
        /** Drawn as selected, e.g. while its menu is open. */
        highlighted?: boolean;
        onOpen?: (transaction: DecryptedTransaction) => void;
    }) {
    const transaction = row.original;

    const handleRowClick = (event: MouseEvent<HTMLTableRowElement>) => {
        const target = event.target as HTMLElement;
        if (!onOpen || target.closest(INTERACTIVE_TARGETS)) {
            return;
        }
        onOpen(transaction);
    };

    return (
        <TableRow
            {...props}
            ref={rowVirtualizer.measureElement}
            data-state={(row.getIsSelected() || highlighted) && 'selected'}
            data-index={virtualRow.index}
            className={cn(
                onOpen && 'cursor-pointer',
                isNew && 'bg-blue-500/5 dark:bg-blue-500/10',
            )}
            onClick={handleRowClick}
            data-testid="transaction-table-row"
        >
            {row
                .getVisibleCells()
                .filter(
                    (cell: Cell<DecryptedTransaction, unknown>) =>
                        !(cell.column.columnDef.meta as ColumnMeta)?.isVirtual,
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
    );
}
