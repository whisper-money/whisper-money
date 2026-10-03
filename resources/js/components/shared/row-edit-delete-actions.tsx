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
import { Fragment, type ReactNode, useState } from 'react';

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

/** A page-specific row action, listed between Edit and Delete. */
export interface RowAction {
    label: string;
    renderDialog: DialogRenderer;
}

interface RowActionDialogs {
    renderEditDialog: DialogRenderer;
    renderDeleteDialog: DialogRenderer;
    extraActions?: RowAction[];
}

interface KeyedRowAction extends RowAction {
    key: string;
    variant: 'default' | 'destructive';
}

/**
 * The state behind both menus: one dialog open at a time, keyed by the entry
 * that opened it. Returns the entries in menu order (Edit, the extra actions,
 * then the destructive Delete last) and the dialogs to render beside the menu,
 * outside it, so they outlive the menu closing.
 */
function useRowActions({
    renderEditDialog,
    renderDeleteDialog,
    extraActions = [],
}: RowActionDialogs) {
    const [openKey, setOpenKey] = useState<string | null>(null);

    const actions: KeyedRowAction[] = [
        {
            key: 'edit',
            label: __('Edit'),
            renderDialog: renderEditDialog,
            variant: 'default',
        },
        ...extraActions.map((action, index) => ({
            ...action,
            key: `extra-${index}`,
            variant: 'default' as const,
        })),
        {
            key: 'delete',
            label: __('Delete'),
            renderDialog: renderDeleteDialog,
            variant: 'destructive',
        },
    ];

    const entries = actions.map(({ key, label, variant }) => ({
        key,
        label,
        variant,
        select: () => setOpenKey(key),
    }));

    const dialogs = actions.map(({ key, renderDialog }) => (
        <Fragment key={key}>
            {renderDialog({
                open: openKey === key,
                onOpenChange: (open) => setOpenKey(open ? key : null),
            })}
        </Fragment>
    ));

    return { entries, dialogs };
}

/**
 * The trailing "..." cell of a settings table: edit, any extra actions and
 * delete, each opening the dialog the page passed in.
 */
export function RowActionsDropdown(props: RowActionDialogs) {
    const { entries, dialogs } = useRowActions(props);

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
                    {entries.map((entry) => (
                        <DropdownMenuItem
                            key={entry.key}
                            onClick={entry.select}
                            variant={entry.variant}
                        >
                            {entry.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {dialogs}
        </>
    );
}

/**
 * A settings table row that offers the same actions on right-click, staying
 * highlighted while its menu is open.
 */
export function RowWithActionsContextMenu<TData>({
    row,
    ...dialogProps
}: { row: Row<TData> } & RowActionDialogs) {
    const { entries, dialogs } = useRowActions(dialogProps);
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
                    {entries.map((entry) => (
                        <ContextMenuItem
                            key={entry.key}
                            onClick={entry.select}
                            variant={entry.variant}
                        >
                            {entry.label}
                        </ContextMenuItem>
                    ))}
                </ContextMenuContent>
            </ContextMenu>

            {dialogs}
        </>
    );
}
