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
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface DisconnectDialogProps {
    connection: BankingConnection;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function DisconnectDialog({
    connection,
    open,
    onOpenChange,
}: DisconnectDialogProps) {
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [deleteAccounts, setDeleteAccounts] = useState<boolean | null>(null);
    const [confirmation, setConfirmation] = useState('');

    function handleDisconnect() {
        setIsSubmitting(true);

        router.delete(`/settings/connections/${connection.id}`, {
            data: {
                delete_accounts: deleteAccounts ?? false,
                confirmation: deleteAccounts ? confirmation : null,
            },
            onFinish: () => {
                setIsSubmitting(false);
                onOpenChange(false);
            },
        });
    }

    function handleOpenChange(value: boolean) {
        if (!value) {
            setDeleteAccounts(null);
            setConfirmation('');
        }
        onOpenChange(value);
    }

    const isConfirmed = deleteAccounts
        ? confirmation.toLowerCase() === 'delete all'
        : deleteAccounts !== null;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Disconnect Bank')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'This will revoke access to your bank account data from :bank.',
                            { bank: connection.aspsp_name },
                        )}
                    </DialogDescription>
                </DialogHeader>

                {connection.accounts_count > 0 && (
                    <div className="space-y-3">
                        <p className="text-sm font-medium">
                            {__(
                                'This connection has :count associated account(s). What would you like to do with them?',
                                {
                                    count: String(connection.accounts_count),
                                },
                            )}
                        </p>

                        <div className="space-y-2">
                            <button
                                type="button"
                                onClick={() => {
                                    setDeleteAccounts(false);
                                    setConfirmation('');
                                }}
                                className={`w-full rounded-md border p-3 text-left text-sm transition-colors ${
                                    deleteAccounts === false
                                        ? 'border-primary bg-primary/5'
                                        : 'border-border hover:border-muted-foreground/50'
                                }`}
                            >
                                <span className="font-medium">
                                    {__('Keep accounts')}
                                </span>
                                <p className="mt-0.5 text-muted-foreground">
                                    {__(
                                        'Accounts will become manual. All transactions and balances will be preserved.',
                                    )}
                                </p>
                            </button>

                            <button
                                type="button"
                                onClick={() => setDeleteAccounts(true)}
                                className={`w-full rounded-md border p-3 text-left text-sm transition-colors ${
                                    deleteAccounts === true
                                        ? 'border-destructive bg-destructive/5'
                                        : 'border-border hover:border-muted-foreground/50'
                                }`}
                            >
                                <span className="font-medium text-destructive">
                                    {__('Delete accounts')}
                                </span>
                                <p className="mt-0.5 text-muted-foreground">
                                    {__(
                                        'All associated accounts, transactions and balances will be permanently deleted.',
                                    )}
                                </p>
                            </button>
                        </div>

                        {deleteAccounts === true && (
                            <div className="space-y-2">
                                <Label htmlFor="confirmation">
                                    {__('Type "delete all" to confirm:')}
                                </Label>
                                <Input
                                    id="confirmation"
                                    value={confirmation}
                                    onChange={(e) =>
                                        setConfirmation(e.target.value)
                                    }
                                    placeholder="delete all"
                                    autoComplete="off"
                                />
                            </div>
                        )}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                        disabled={isSubmitting}
                    >
                        {__('Cancel')}
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleDisconnect}
                        disabled={
                            isSubmitting ||
                            (connection.accounts_count > 0 && !isConfirmed)
                        }
                    >
                        {isSubmitting
                            ? __('Disconnecting...')
                            : __('Disconnect')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
