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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { type TransactionDeleteDialogState } from '@/hooks/use-transaction-bulk-actions';
import { getBulkDeleteConfirmationText } from '@/lib/transaction-delete-confirmation';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

interface TransactionDeleteDialogsProps {
    dialog: TransactionDeleteDialogState;
    /** Make the user type the count before deleting several rows. */
    confirmBulkByTyping?: boolean;
}

function UpdateBalanceOption({
    dialog,
    label,
}: {
    dialog: TransactionDeleteDialogState;
    label: string;
}) {
    if (!dialog.touchesManualAccount) {
        return null;
    }

    return (
        <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-muted/40 p-3 text-sm">
            <Checkbox
                checked={dialog.updateBalance}
                onCheckedChange={(checked) =>
                    dialog.setUpdateBalance(checked === true)
                }
                className="mt-0.5"
            />
            <span className="text-muted-foreground">{label}</span>
        </label>
    );
}

/**
 * The confirmation before deleting one transaction from its row, or the
 * ticked ones from the bulk bar. A balance-owning account offers to move
 * its balance by what goes.
 */
export function TransactionDeleteDialogs({
    dialog,
    confirmBulkByTyping = false,
}: TransactionDeleteDialogsProps) {
    const isOpen = dialog.transaction !== null;

    return (
        <>
            <AlertDialog
                open={isOpen && !(dialog.isBulk && confirmBulkByTyping)}
                onOpenChange={(open) => !open && dialog.onClose()}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {dialog.isBulk
                                ? __('Delete Transactions')
                                : __('Delete Transaction')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {dialog.isBulk
                                ? __(
                                      'Are you sure you want to delete :count transactions? This action cannot be undone.',
                                      { count: dialog.selectedCount },
                                  )
                                : __(
                                      'Are you sure you want to delete this transaction? This action cannot be undone.',
                                  )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <UpdateBalanceOption
                        dialog={dialog}
                        label={__(
                            'Update the current balance of the manual account to reflect this change.',
                        )}
                    />
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={dialog.isDeleting}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={dialog.onConfirm}
                            disabled={dialog.isDeleting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {dialog.isDeleting
                                ? __('Deleting...')
                                : __('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {confirmBulkByTyping && (
                <Dialog
                    open={isOpen && dialog.isBulk}
                    onOpenChange={(open) => !open && dialog.onClose()}
                >
                    <DialogContent>
                        {/* Unmounted on close, so the typed text starts empty. */}
                        <TypedBulkDeleteConfirmation dialog={dialog} />
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}

function TypedBulkDeleteConfirmation({
    dialog,
}: {
    dialog: TransactionDeleteDialogState;
}) {
    const [typed, setTyped] = useState('');
    const confirmationText = getBulkDeleteConfirmationText(
        dialog.selectedCount,
    );

    return (
        <>
            <DialogHeader>
                <DialogTitle>{__('Delete Transactions')}</DialogTitle>
                <DialogDescription>
                    {__('This action cannot be undone. To confirm, type')}{' '}
                    <span className="font-semibold text-foreground">
                        {confirmationText}
                    </span>
                </DialogDescription>
            </DialogHeader>
            <Input
                value={typed}
                onChange={(event) => setTyped(event.target.value)}
                placeholder={confirmationText}
                disabled={dialog.isDeleting}
                autoFocus
            />
            <UpdateBalanceOption
                dialog={dialog}
                label={__(
                    'Update the current balance of the affected manual accounts to reflect this change.',
                )}
            />
            <DialogFooter>
                <Button
                    variant="outline"
                    onClick={dialog.onClose}
                    disabled={dialog.isDeleting}
                >
                    {__('Cancel')}
                </Button>
                <Button
                    variant="destructive"
                    onClick={dialog.onConfirm}
                    disabled={dialog.isDeleting || typed !== confirmationText}
                >
                    {dialog.isDeleting ? __('Deleting...') : __('Delete')}
                </Button>
            </DialogFooter>
        </>
    );
}
