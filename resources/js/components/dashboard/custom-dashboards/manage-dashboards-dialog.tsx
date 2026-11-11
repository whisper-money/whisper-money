import { SortableGrid } from '@/components/sortable-grid';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { type DashboardSummary } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { Pencil, Star, Trash2 } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { dashboardActions } from './dashboard-actions';
import { DashboardNameDialog } from './dashboard-name-dialog';
import { systemDashboardName } from './labels';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    dashboards: DashboardSummary[];
    defaultId: string | null;
    /** Renaming and ordering need the paid plan; deleting and the default do not. */
    canEdit: boolean;
}

/**
 * Orders, renames and deletes the user's dashboards, and picks the one the app
 * opens on. The system dashboard is listed first and can only be the default.
 */
export function ManageDashboardsDialog({
    open,
    onOpenChange,
    dashboards,
    defaultId,
    canEdit,
}: Props) {
    const [renaming, setRenaming] = useState<DashboardSummary | null>(null);
    const [deleting, setDeleting] = useState<DashboardSummary | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const row = (
        entry: { id: string | null; name: string },
        leading: ReactNode,
        actions?: ReactNode,
    ) => {
        const isDefault = entry.id === defaultId;

        return (
            <div
                data-testid="manage-dashboard-row"
                className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2"
            >
                {leading}
                <span className="min-w-0 flex-1 truncate text-sm">
                    {entry.name}
                </span>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-pressed={isDefault}
                    aria-label={
                        isDefault
                            ? __('Default dashboard')
                            : __('Make :name the default dashboard', {
                                  name: entry.name,
                              })
                    }
                    disabled={isDefault}
                    onClick={() => dashboardActions.setDefault(entry.id)}
                >
                    <Star
                        className={cn(
                            'size-4',
                            isDefault && 'fill-amber-400 text-amber-400',
                        )}
                    />
                </Button>
                {actions}
            </div>
        );
    };

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{__('Manage dashboards')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'The starred dashboard is the one the app opens on.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-col gap-2">
                        {row(
                            { id: null, name: systemDashboardName() },
                            <span className="size-5" aria-hidden />,
                        )}
                        <SortableGrid
                            layout="list"
                            className="flex flex-col gap-2"
                            items={dashboards}
                            getId={(item) => item.id}
                            onReorder={dashboardActions.reorderDashboards}
                            renderItem={(item, dragHandle) =>
                                row(
                                    item,
                                    canEdit ? (
                                        dragHandle
                                    ) : (
                                        <span className="size-5" aria-hidden />
                                    ),
                                    <>
                                        {canEdit && (
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={__('Rename :name', {
                                                    name: item.name,
                                                })}
                                                onClick={() =>
                                                    setRenaming(item)
                                                }
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={__('Delete :name', {
                                                name: item.name,
                                            })}
                                            onClick={() => setDeleting(item)}
                                        >
                                            <Trash2 className="size-4 text-destructive" />
                                        </Button>
                                    </>,
                                )
                            }
                        />
                    </div>
                </DialogContent>
            </Dialog>

            <DashboardNameDialog
                open={renaming !== null}
                onOpenChange={(isOpen) => !isOpen && setRenaming(null)}
                dashboard={renaming ?? undefined}
            />

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(isOpen) => !isOpen && setDeleting(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Delete :name?', {
                                name: deleting?.name ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {__(
                                'The dashboard and its widgets are deleted. Your data stays as it is. This cannot be undone.',
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={isDeleting}
                            onClick={(event) => {
                                event.preventDefault();
                                if (deleting === null) {
                                    return;
                                }
                                setIsDeleting(true);
                                dashboardActions.destroyDashboard(
                                    deleting.id,
                                    () => {
                                        setIsDeleting(false);
                                        setDeleting(null);
                                    },
                                );
                            }}
                        >
                            {isDeleting ? __('Deleting...') : __('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
