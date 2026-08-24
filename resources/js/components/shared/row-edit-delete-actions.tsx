import { Button } from '@/components/ui/button';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { __ } from '@/utils/i18n';
import { type Cell, flexRender, type Row } from '@tanstack/react-table';
import { MoreHorizontal } from 'lucide-react';
import { type ReactNode, useState } from 'react';

/** The open state a menu hands to the dialog it triggers. */
export interface DialogControl {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * Renders a dialog whose open state is owned by the menu that triggers it, so
 * each settings page keeps its own edit/delete dialogs and props.
 */
type DialogRenderer = (control: DialogControl) => ReactNode;

interface EditDeleteDialogs {
    renderEditDialog: DialogRenderer;
    renderDeleteDialog: DialogRenderer;
}

/**
 * The trailing "..." cell of a settings table: edit and delete, each opening
 * the dialog the page passed in.
 */
export function RowActionsDropdown({
    renderEditDialog,
    renderDeleteDialog,
}: EditDeleteDialogs) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="h-8 w-8 p-0">
                        <span className="sr-only">{__('Open menu')}</span>
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>{__('Actions')}</DropdownMenuLabel>
                    <DropdownMenuItem onClick={() => setEditOpen(true)}>
                        {__('Edit')}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => setDeleteOpen(true)}
                        variant="destructive"
                    >
                        {__('Delete')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {renderEditDialog({ open: editOpen, onOpenChange: setEditOpen })}
            {renderDeleteDialog({
                open: deleteOpen,
                onOpenChange: setDeleteOpen,
            })}
        </>
    );
}

/**
 * A settings table row that offers the same edit and delete actions on
 * right-click, staying highlighted while its menu is open.
 */
export function RowWithActionsContextMenu<TData>({
    row,
    renderEditDialog,
    renderDeleteDialog,
}: { row: Row<TData> } & EditDeleteDialogs) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [contextMenuOpen, setContextMenuOpen] = useState(false);

    return (
        <>
            <ContextMenu onOpenChange={setContextMenuOpen}>
                <ContextMenuTrigger asChild>
                    <TableRow
                        data-state={
                            (row.getIsSelected() || contextMenuOpen) &&
                            'selected'
                        }
                    >
                        {row
                            .getVisibleCells()
                            .map((cell: Cell<TData, unknown>) => (
                                <TableCell
                                    key={cell.id}
                                    className="align-middle"
                                >
                                    {flexRender(
                                        cell.column.columnDef.cell,
                                        cell.getContext(),
                                    )}
                                </TableCell>
                            ))}
                    </TableRow>
                </ContextMenuTrigger>
                <ContextMenuContent>
                    <ContextMenuLabel>{__('Actions')}</ContextMenuLabel>
                    <ContextMenuItem onClick={() => setEditOpen(true)}>
                        {__('Edit')}
                    </ContextMenuItem>
                    <ContextMenuItem
                        onClick={() => setDeleteOpen(true)}
                        variant="destructive"
                    >
                        {__('Delete')}
                    </ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            {renderEditDialog({ open: editOpen, onOpenChange: setEditOpen })}
            {renderDeleteDialog({
                open: deleteOpen,
                onOpenChange: setDeleteOpen,
            })}
        </>
    );
}
