import {
    destroy,
    index,
} from '@/actions/App/Http/Controllers/AccountBalanceController';
import { store } from '@/actions/App/Http/Controllers/Sync/AccountBalanceSyncController';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { accountBalanceSyncService } from '@/services/account-balance-sync';
import type { Account, AccountBalance } from '@/types/account';
import { Pencil, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface BalancesModalProps {
    account: Account;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onBalanceChange?: () => void;
}

interface PaginatedResponse {
    data: AccountBalance[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export function BalancesModal({
    account,
    open,
    onOpenChange,
    onBalanceChange,
}: BalancesModalProps) {
    const [balances, setBalances] = useState<AccountBalance[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [currentPage, setCurrentPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);

    const [editingBalance, setEditingBalance] = useState<AccountBalance | null>(
        null,
    );
    const [editDate, setEditDate] = useState('');
    const [editAmount, setEditAmount] = useState(0);
    const [isEditSubmitting, setIsEditSubmitting] = useState(false);

    const [deletingBalance, setDeletingBalance] =
        useState<AccountBalance | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: account.currency_code,
    });

    const fetchBalances = useCallback(
        async (page: number) => {
            setIsLoading(true);
            try {
                const response = await fetch(
                    index.url(account.id, { query: { page: String(page) } }),
                    {
                        headers: {
                            Accept: 'application/json',
                        },
                    },
                );

                if (!response.ok) {
                    throw new Error('Failed to fetch balances');
                }

                const data: PaginatedResponse = await response.json();
                setBalances(data.data);
                setCurrentPage(data.current_page);
                setLastPage(data.last_page);
                setTotal(data.total);
            } catch (err) {
                console.error('Failed to fetch balances:', err);
            } finally {
                setIsLoading(false);
            }
        },
        [account.id],
    );

    useEffect(() => {
        if (open) {
            fetchBalances(1);
        } else {
            setBalances([]);
            setCurrentPage(1);
            setLastPage(1);
            setTotal(0);
        }
    }, [open, fetchBalances]);

    function handleEditClick(balance: AccountBalance) {
        setEditingBalance(balance);
        setEditDate(balance.balance_date.split('T')[0]);
        setEditAmount(balance.balance);
    }

    async function handleEditSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!editingBalance) return;

        setIsEditSubmitting(true);
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
                    balance_date: editDate,
                    balance: editAmount,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to update balance');
            }

            await accountBalanceSyncService.sync();

            setEditingBalance(null);
            fetchBalances(currentPage);
            onBalanceChange?.();
        } catch (err) {
            console.error('Failed to update balance:', err);
        } finally {
            setIsEditSubmitting(false);
        }
    }

    async function handleDelete() {
        if (!deletingBalance) return;

        setIsDeleting(true);
        try {
            const response = await fetch(
                destroy.url({
                    account: account.id,
                    accountBalance: deletingBalance.id,
                }),
                {
                    method: 'DELETE',
                    headers: {
                        'X-XSRF-TOKEN': decodeURIComponent(
                            document.cookie
                                .split('; ')
                                .find((row) => row.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] || '',
                        ),
                        Accept: 'application/json',
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Failed to delete balance');
            }

            await accountBalanceSyncService.sync();

            setDeletingBalance(null);
            fetchBalances(currentPage);
            onBalanceChange?.();
        } catch (err) {
            console.error('Failed to delete balance:', err);
        } finally {
            setIsDeleting(false);
        }
    }

    function formatDate(dateString: string): string {
        const date = new Date(dateString);
        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    }

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="sm:max-w-[600px]">
                    <DialogHeader>
                        <DialogTitle>Balance History</DialogTitle>
                        <DialogDescription>
                            View and manage balance records for this account.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        className="overflow-hidden rounded-md border"
                        style={{ maxHeight: 400 }}
                    >
                        <div
                            className="overflow-y-auto"
                            style={{ maxHeight: 400 }}
                        >
                            <Table>
                                <TableHeader className="sticky top-0 z-10 bg-background">
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">
                                            Balance
                                        </TableHead>
                                        <TableHead className="w-[100px] text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {isLoading ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={3}
                                                className="h-24 text-center"
                                            >
                                                Loading...
                                            </TableCell>
                                        </TableRow>
                                    ) : balances.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={3}
                                                className="h-24 text-center"
                                            >
                                                No balance records found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        balances.map((balance) => (
                                            <TableRow key={balance.id}>
                                                <TableCell>
                                                    {formatDate(
                                                        balance.balance_date,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {formatter.format(
                                                        balance.balance / 100,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                handleEditClick(
                                                                    balance,
                                                                )
                                                            }
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                setDeletingBalance(
                                                                    balance,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="h-4 w-4 text-destructive" />
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>

                    {lastPage > 1 && (
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">
                                {total} balance record{total !== 1 ? 's' : ''}
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        fetchBalances(currentPage - 1)
                                    }
                                    disabled={currentPage <= 1 || isLoading}
                                >
                                    Previous
                                </Button>
                                <span className="text-sm">
                                    Page {currentPage} of {lastPage}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        fetchBalances(currentPage + 1)
                                    }
                                    disabled={
                                        currentPage >= lastPage || isLoading
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog
                open={editingBalance !== null}
                onOpenChange={(open) => !open && setEditingBalance(null)}
            >
                <DialogContent className="sm:max-w-[400px]">
                    <DialogHeader>
                        <DialogTitle>Edit Balance</DialogTitle>
                        <DialogDescription>
                            Update the balance record.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleEditSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-amount">Balance</Label>
                            <AmountInput
                                id="edit-amount"
                                className="mt-1"
                                value={editAmount}
                                onChange={setEditAmount}
                                currencyCode={account.currency_code}
                                required
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="edit-date">Date</Label>
                            <Input
                                id="edit-date"
                                className="mt-1"
                                type="date"
                                value={editDate}
                                onChange={(e) => setEditDate(e.target.value)}
                                required
                            />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditingBalance(null)}
                                disabled={isEditSubmitting}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isEditSubmitting}>
                                {isEditSubmitting ? 'Saving...' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={deletingBalance !== null}
                onOpenChange={(open) => !open && setDeletingBalance(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete balance</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete this balance record?
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant={'destructive'}
                            onClick={handleDelete}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
