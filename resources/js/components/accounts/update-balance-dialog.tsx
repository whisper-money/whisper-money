import { store } from '@/actions/App/Http/Controllers/Sync/AccountBalanceSyncController';
import { AmountInput } from '@/components/ui/amount-input';
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
import { accountBalanceSyncService } from '@/services/account-balance-sync';
import type { Account } from '@/types/account';
import { useState } from 'react';

interface UpdateBalanceDialogProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

function getTodayDate(): string {
    const today = new Date();
    return today.toISOString().split('T')[0];
}

export function UpdateBalanceDialog({
    account,
    open,
    onOpenChange,
    onSuccess,
}: UpdateBalanceDialogProps) {
    const [date, setDate] = useState(getTodayDate());
    const [balance, setBalance] = useState(0);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function handleOpenChange(newOpen: boolean) {
        if (!newOpen) {
            setDate(getTodayDate());
            setBalance(0);
            setError(null);
        }
        onOpenChange(newOpen);
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setIsSubmitting(true);
        setError(null);

        try {
            const response = await fetch(store.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((row) => row.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1] || '',
                    ),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    account_id: account.id,
                    balance_date: date,
                    balance: balance,
                }),
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to update balance');
            }

            await accountBalanceSyncService.sync();

            handleOpenChange(false);
            onSuccess?.();
        } catch (err) {
            setError(
                err instanceof Error ? err.message : 'Failed to update balance',
            );
        } finally {
            setIsSubmitting(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[400px]">
                <DialogHeader>
                    <DialogTitle>Update balance</DialogTitle>
                    <DialogDescription>
                        Set the balance for this account on a specific date.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="balance-amount">Balance</Label>
                        <AmountInput
                            id="balance-amount"
                            className="mt-1"
                            value={balance}
                            onChange={setBalance}
                            currencyCode={account.currency_code}
                            required
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="balance-date">Date</Label>
                        <Input
                            id="balance-date"
                            type="date"
                            className="mt-1"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            required
                        />
                    </div>

                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => handleOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Saving...' : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
