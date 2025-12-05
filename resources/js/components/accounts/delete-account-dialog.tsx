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
import { db } from '@/lib/dexie-db';
import { type Account } from '@/types/account';
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

    function handleOpenChange(newOpen: boolean) {
        if (!newOpen) {
            setConfirmText('');
        }
        onOpenChange(newOpen);
    }

    const isDeleteEnabled = confirmText === 'DELETE';

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Delete Account</DialogTitle>
                    <DialogDescription className="space-y-2">
                        <p>
                            This action is irreversible. All transactions in
                            this account will also be permanently deleted.
                        </p>
                        <p className="font-semibold">
                            Type <span className="text-red-600">DELETE</span> to
                            confirm.
                        </p>
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="confirm">Confirmation</Label>
                        <Input
                            id="confirm"
                            className="mt-1"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            placeholder="Type DELETE"
                            autoComplete="off"
                        />
                    </div>

                    <Form
                        {...destroy.form.delete(account.id)}
                        onSuccess={async () => {
                            await db.accounts.delete(account.id);
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
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing || !isDeleteEnabled}
                                >
                                    {processing ? 'Deleting...' : 'Delete'}
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </div>
            </DialogContent>
        </Dialog>
    );
}
