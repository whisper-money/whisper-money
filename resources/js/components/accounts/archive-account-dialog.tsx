import { updateArchived } from '@/actions/App/Http/Controllers/AccountController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';

interface ArchiveAccountDialogProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

/**
 * Archiving is reversible, so it asks for a plain confirmation rather than a
 * typed one — but it reaches enough of the app that it has to say what it does.
 * Unarchiving needs no dialog at all.
 *
 * The one half that is not reversible is the bank connection: archiving a
 * connected account detaches it, so that consequence is spelled out first.
 */
export function ArchiveAccountDialog({
    account,
    open,
    onOpenChange,
    onSuccess,
}: ArchiveAccountDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[480px]">
                <DialogHeader>
                    <DialogTitle>{__('Archive account')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Archiving :name puts it away without deleting anything:',
                            { name: account.name },
                        )}
                    </DialogDescription>
                </DialogHeader>

                <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                    {!!account.banking_connection_id && (
                        <li className="font-medium text-foreground">
                            {__(
                                'It will be disconnected from the bank: no new transactions or balances will come in. Unarchiving brings the account back, not the connection — you would have to connect the bank again.',
                            )}
                        </li>
                    )}
                    <li>
                        {__(
                            'It disappears from the dashboard and from the accounts page.',
                        )}
                    </li>
                    <li>
                        {__(
                            'It stops counting towards your net worth, your spending and your category totals from today. The months before today keep the figures they already have.',
                        )}
                    </li>
                    <li>
                        {__(
                            'You will not be able to pick it for new transactions, but the ones it already has stay in your history and stay editable.',
                        )}
                    </li>
                    <li>
                        {__(
                            'You can bring it back any time from the Bank accounts settings.',
                        )}
                    </li>
                </ul>

                <Form
                    {...updateArchived.form.patch(account.id)}
                    onSuccess={() => {
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                >
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="archived" value="1" />
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? __('Archiving...')
                                        : __('Archive account')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
