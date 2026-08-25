import { syncTransactions } from '@/actions/App/Http/Controllers/SavingsGoalController';
import { LabelBadge } from '@/components/shared/label-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { SavingsGoal } from '@/types/savings-goal';
import { ServerTransaction } from '@/types/transaction';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    savingsGoal: SavingsGoal;
    /** Everything already carrying the goal's label, straight from the page. */
    transactions: ServerTransaction[];
    /** The user's latest transactions, loaded on demand when the dialog opens. */
    recentTransactions?: ServerTransaction[];
    /** How many more the load-more link asks for, straight from the controller. */
    recentPageSize: number;
    currencyCode: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function LinkTransactionsDialog({
    savingsGoal,
    transactions,
    recentTransactions,
    recentPageSize,
    currencyCode,
    open,
    onOpenChange,
}: Props) {
    const locale = useLocale();
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [recentLimit, setRecentLimit] = useState(recentPageSize);
    const [isLoadingMore, setIsLoadingMore] = useState(false);

    // The already-tagged ones come first so nothing you can untick is ever off
    // the list — that is what makes sending the whole set back safe.
    const candidates = useMemo(() => {
        const byId = new Map<string, ServerTransaction>();

        for (const transaction of [
            ...transactions,
            ...(recentTransactions ?? []),
        ]) {
            byId.set(transaction.id, transaction);
        }

        return Array.from(byId.values()).sort((a, b) =>
            b.transaction_date.localeCompare(a.transaction_date),
        );
    }, [transactions, recentTransactions]);

    useEffect(() => {
        if (!open) {
            return;
        }

        setSelected(new Set(transactions.map((transaction) => transaction.id)));

        if (recentTransactions === undefined) {
            router.reload({ only: ['recentTransactions'] });
        }
        // Re-seeding on every recentTransactions change would wipe the user's ticks.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // A short page means the query ran out of transactions, so there is nothing
    // left to widen the window for.
    const hasMore =
        recentTransactions !== undefined &&
        recentTransactions.length >= recentLimit;

    const loadMore = () => {
        const nextLimit = recentLimit + recentPageSize;

        // A spinner that just stops leaves the user thinking nothing happened, and
        // Inertia's own error handling would tear the page down under the dialog.
        const reportFailure = (): false => {
            toast.error(__('Failed to load more transactions.'));

            return false;
        };

        setIsLoadingMore(true);

        router.reload({
            only: ['recentTransactions'],
            data: { recent: nextLimit },
            // The window the dialog wants is not something to leave in the address
            // bar: a reopen would then load a wider window than it thinks it has.
            preserveUrl: true,
            onSuccess: () => setRecentLimit(nextLimit),
            onHttpException: reportFailure,
            onNetworkError: reportFailure,
            onFinish: () => setIsLoadingMore(false),
        });
    };

    // A transaction can back more than one goal, and then it counts toward both.
    // Surfacing the other goals' labels is what makes that visible before ticking.
    const otherGoalLabels = (transaction: ServerTransaction) =>
        (transaction.labels ?? []).filter(
            (label) =>
                label.source === 'saving_goal' &&
                label.id !== savingsGoal.label_id,
        );

    const toggle = (id: string) => {
        setSelected((previous) => {
            const next = new Set(previous);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const handleSubmit = () => {
        setIsSubmitting(true);

        router.put(
            syncTransactions({ savingsGoal: savingsGoal.id }).url,
            { transaction_ids: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: () =>
                    toast.error(
                        __('Failed to update transactions with labels'),
                    ),
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>{__('Link transactions')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Tick the transactions that count toward this goal. Unticking one removes it from the goal.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div className="-mx-2 max-h-[50vh] overflow-y-auto px-2">
                    {recentTransactions !== undefined &&
                        candidates.length === 0 && (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                {__('No transactions found.')}
                            </p>
                        )}

                    <div className="divide-y">
                        {candidates.map((transaction) => (
                            <label
                                key={transaction.id}
                                className="flex cursor-pointer items-center gap-3 py-2 text-sm"
                            >
                                <Checkbox
                                    checked={selected.has(transaction.id)}
                                    onCheckedChange={() =>
                                        toggle(transaction.id)
                                    }
                                />
                                <span className="w-16 shrink-0 text-muted-foreground">
                                    {formatDate(
                                        transaction.transaction_date,
                                        'MMM d',
                                        locale,
                                    )}
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {transaction.description}
                                </span>
                                {otherGoalLabels(transaction).map((label) => (
                                    <LabelBadge key={label.id} label={label} />
                                ))}
                                <AmountDisplay
                                    amountInCents={transaction.amount}
                                    currencyCode={currencyCode}
                                    className={cn(
                                        'shrink-0 tabular-nums',
                                        transaction.amount > 0 &&
                                            'text-emerald-600 dark:text-emerald-400',
                                    )}
                                />
                            </label>
                        ))}
                    </div>

                    {recentTransactions === undefined && (
                        <div className="space-y-2 py-2">
                            {Array.from({ length: 5 }).map((_, index) => (
                                <Skeleton key={index} className="h-10 w-full" />
                            ))}
                        </div>
                    )}

                    {hasMore && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-full"
                            onClick={loadMore}
                            disabled={isLoadingMore}
                        >
                            {isLoadingMore ? (
                                <>
                                    <Spinner />
                                    {__('Loading')}
                                </>
                            ) : (
                                __('Load more')
                            )}
                        </Button>
                    )}
                </div>

                <DialogFooter className="sm:items-center sm:justify-between">
                    <span className="text-sm text-muted-foreground">
                        {__(':count selected', { count: selected.size })}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="button"
                            onClick={handleSubmit}
                            disabled={isSubmitting}
                        >
                            {isSubmitting
                                ? __('Saving...')
                                : __('Save changes')}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
