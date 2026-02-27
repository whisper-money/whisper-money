import { destroy } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Form, router } from '@inertiajs/react';
import { useState } from 'react';

interface DeleteAccountDialogProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
    redirectTo?: string;
}

export function DeleteAccountDialog({
    account,
    open,
    onOpenChange,
    onSuccess,
    redirectTo,
}: DeleteAccountDialogProps) {
    const [confirmText, setConfirmText] = useState('');

    const confirmWord = __('DELETE');

    function handleOpenChange(newOpen: boolean) {
        if (!newOpen) {
            setConfirmText('');
        }
        onOpenChange(newOpen);
    }

    const isDeleteEnabled = confirmText === confirmWord;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Delete Account')}</DialogTitle>
                    <DialogDescription className="space-y-2">
                        <p>
                            {__(
                                'This action is irreversible. All transactions in this account will also be permanently deleted.',
                            )}
                        </p>
                        <p
                            className="font-semibold"
                            dangerouslySetInnerHTML={{
                                __html: __('Type :word to confirm.', {
                                    word: `<span class="text-red-600">${confirmWord}</span>`,
                                }),
                            }}
                        />
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="confirm">{__('Confirmation')}</Label>
                        <Input
                            id="confirm"
                            className="mt-1"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            placeholder={__('Type DELETE')}
                            autoComplete="off"
                        />
                    </div>

                    <Form
                        {...destroy.form.delete(account.id)}
                        onSuccess={() => {
                            handleOpenChange(false);
                            if (redirectTo) {
                                router.visit(redirectTo);
                            } else {
                                onSuccess?.();
                            }
                        }}
                    >
                        {({ processing }) => (
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => handleOpenChange(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing || !isDeleteEnabled}
                                >
                                    {processing
                                        ? __('Deleting...')
                                        : __('Delete')}
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </div>
            </DialogContent>
        </Dialog>
    );
}
