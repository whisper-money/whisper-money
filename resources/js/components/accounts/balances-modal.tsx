import {
    destroy,
    index,
    store,
} from '@/actions/App/Http/Controllers/AccountBalanceController';
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
import { useLocale } from '@/hooks/use-locale';
import { getCsrfToken } from '@/lib/csrf';
import type { SharedData } from '@/types';
import type { Account, AccountBalance } from '@/types/account';
import {
    balanceTermCapitalized,
    supportsInvestedAmount,
} from '@/types/account';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
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
    const locale = useLocale();
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
    const [editInvestedAmount, setEditInvestedAmount] = useState<number | null>(
        null,
    );
    const [isEditSubmitting, setIsEditSubmitting] = useState(false);

    const [deletingBalance, setDeletingBalance] =
        useState<AccountBalance | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const formatBalance = (valueInCents: number) =>
        formatCurrency(valueInCents, account.currency_code, locale);

    const userCurrencyCode =
        usePage<SharedData>().props.auth.user.currency_code;
    const formatInvestedAmount = (valueInCents: number) =>
        formatCurrency(valueInCents, userCurrencyCode, locale);

    const showInvestedAmount = supportsInvestedAmount(account);
    const isLoan = account.type === 'loan';

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
        setEditInvestedAmount(balance.invested_amount);
    }

    async function handleEditSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!editingBalance) return;

        setIsEditSubmitting(true);
        try {
            const response = await fetch(store.url(account.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    balance_date: editDate,
                    balance: editAmount,
                    ...(showInvestedAmount && editInvestedAmount !== null
                        ? { invested_amount: editInvestedAmount }
                        : {}),
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to update balance');
            }

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
                        'X-XSRF-TOKEN': getCsrfToken(),
                        Accept: 'application/json',
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Failed to delete balance');
            }

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
        return date.toLocaleDateString(locale, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    }

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent
                    className={
                        showInvestedAmount
                            ? 'sm:max-w-[700px]'
                            : 'sm:max-w-[600px]'
                    }
                >
                    <DialogHeader>
                        <DialogTitle>
                            {isLoan
                                ? __('Owed Amount History')
                                : __('Balance History')}
                        </DialogTitle>
                        <DialogDescription>
                            {isLoan
                                ? __(
                                      'View and manage owed amount records for this account.',
                                  )
                                : __(
                                      'View and manage balance records for this account.',
                                  )}
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
                                        <TableHead>{__('Date')}</TableHead>
                                        <TableHead className="text-right">
                                            {balanceTermCapitalized(
                                                account.type,
                                            )}
                                        </TableHead>
                                        {showInvestedAmount && (
                                            <TableHead className="text-right">
                                                {__('Invested')}
                                            </TableHead>
                                        )}
                                        <TableHead className="w-[100px] text-right">
                                            {__('Actions')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {isLoading ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    showInvestedAmount ? 4 : 3
                                                }
                                                className="h-24 text-center"
                                            >
                                                {__('Loading...')}
                                            </TableCell>
                                        </TableRow>
                                    ) : balances.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    showInvestedAmount ? 4 : 3
                                                }
                                                className="h-24 text-center"
                                            >
                                                {isLoan
                                                    ? __(
                                                          'No owed amount records found.',
                                                      )
                                                    : __(
                                                          'No balance records found.',
                                                      )}
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
                                                    {formatBalance(
                                                        balance.balance,
                                                    )}
                                                </TableCell>
                                                {showInvestedAmount && (
                                                    <TableCell className="text-right font-mono text-muted-foreground">
                                                        {balance.invested_amount !==
                                                        null
                                                            ? formatInvestedAmount(
                                                                  balance.invested_amount,
                                                              )
                                                            : '—'}
                                                    </TableCell>
                                                )}
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
                                {total}{' '}
                                {total === 1
                                    ? isLoan
                                        ? __('owed amount record')
                                        : __('balance record')
                                    : isLoan
                                      ? __('owed amount records')
                                      : __('balance records')}
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
                                    {__('Previous')}
                                </Button>
                                <span className="text-sm">
                                    {__('Page')} {currentPage} {__('of')}{' '}
                                    {lastPage}
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
                                    {__('Next')}
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
                        <DialogTitle>
                            {isLoan
                                ? __('Edit Owed Amount')
                                : __('Edit Balance')}
                        </DialogTitle>
                        <DialogDescription>
                            {isLoan
                                ? __('Update the owed amount record.')
                                : __('Update the balance record.')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleEditSubmit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-amount">
                                {balanceTermCapitalized(account.type)}
                            </Label>
                            <AmountInput
                                id="edit-amount"
                                className="mt-1"
                                value={editAmount}
                                onChange={setEditAmount}
                                currencyCode={account.currency_code}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="edit-date">{__('Date')}</Label>
                            <Input
                                id="edit-date"
                                className="mt-1"
                                type="date"
                                value={editDate}
                                onChange={(e) => setEditDate(e.target.value)}
                                required
                            />
                        </div>

                        {showInvestedAmount && (
                            <div className="space-y-2">
                                <Label htmlFor="edit-invested-amount">
                                    {__('Invested amount')}
                                </Label>
                                <AmountInput
                                    id="edit-invested-amount"
                                    className="mt-1"
                                    value={editInvestedAmount ?? 0}
                                    onChange={(value) =>
                                        setEditInvestedAmount(value || null)
                                    }
                                    currencyCode={userCurrencyCode}
                                />
                            </div>
                        )}

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditingBalance(null)}
                                disabled={isEditSubmitting}
                            >
                                {__('Cancel')}
                            </Button>
                            <Button type="submit" disabled={isEditSubmitting}>
                                {isEditSubmitting
                                    ? __('Saving...')
                                    : __('Save')}
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
                        <AlertDialogTitle>
                            {isLoan
                                ? __('Delete owed amount')
                                : __('Delete balance')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {isLoan
                                ? __(
                                      'Are you sure you want to delete this owed amount record? This action cannot be undone.',
                                  )
                                : __(
                                      'Are you sure you want to delete this balance record? This action cannot be undone.',
                                  )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant={'destructive'}
                            onClick={handleDelete}
                            disabled={isDeleting}
                        >
                            {isDeleting ? __('Deleting...') : __('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
