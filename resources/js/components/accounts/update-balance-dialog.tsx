import { index, store } from '@/actions/App/Http/Controllers/AccountBalanceController';
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
import type { Account, AccountBalance } from '@/types/account';
import { useEffect, useRef, useState } from 'react';

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

interface PaginatedBalanceResponse {
    data: AccountBalance[];
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
    const [isLoadingLastBalance, setIsLoadingLastBalance] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        async function fetchLastBalance() {
            if (!open) return;

            setIsLoadingLastBalance(true);
            try {
                const response = await fetch(
                    index.url(account.id, { query: { page: '1' } }),
                    {
                        headers: {
                            Accept: 'application/json',
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error('Failed to fetch last balance');
                }

                const data: PaginatedBalanceResponse = await response.json();
                if (data.data.length > 0) {
                    setBalance(data.data[0].balance);
                } else {
                    setBalance(0);
                }
            } catch (err) {
                console.error('Failed to fetch last balance:', err);
                setBalance(0);
            } finally {
                setIsLoadingLastBalance(false);
            }
        }

        if (open) {
            setDate(getTodayDate());
            setError(null);
            fetchLastBalance();
        }
    }, [open, account.id]);

    useEffect(() => {
        if (open && !isLoadingLastBalance && inputRef.current) {
            setTimeout(() => {
                const input = inputRef.current;
                if (input) {
                    input.focus();
                    // Use requestAnimationFrame to ensure selection happens after focus events
                    requestAnimationFrame(() => {
                        input.setSelectionRange(0, input.value.length);
                    });
                }
            }, 100);
        }
    }, [open, isLoadingLastBalance]);

    function handleOpenChange(newOpen: boolean) {
        onOpenChange(newOpen);
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setIsSubmitting(true);
        setError(null);

        try {
            const response = await fetch(store.url(account.id), {
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
                    balance_date: date,
                    balance: balance,
                }),
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.message || 'Failed to update balance');
            }

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
                        {isLoadingLastBalance ? (
                            <div className="flex h-10 items-center rounded-md border border-input bg-muted px-3 text-sm text-muted-foreground">
                                Loading last balance...
                            </div>
                        ) : (
                            <AmountInput
                                ref={inputRef}
                                id="balance-amount"
                                className="mt-1"
                                value={balance}
                                onChange={setBalance}
                                currencyCode={account.currency_code}
                                required
                            />
                        )}
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
                            disabled={isSubmitting || isLoadingLastBalance}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting || isLoadingLastBalance}
                        >
                            {isSubmitting
                                ? 'Saving...'
                                : isLoadingLastBalance
                                  ? 'Loading...'
                                  : 'Save'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
