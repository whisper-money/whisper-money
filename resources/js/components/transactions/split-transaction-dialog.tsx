import { LabelCombobox } from '@/components/shared/label-combobox';
import { CategorySelect } from '@/components/transactions/category-select';
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
import { useLocale } from '@/hooks/use-locale';
import {
    isSplitBalanced,
    remainingCents,
    toSplitPayload,
    type SplitDraft,
} from '@/lib/transaction-splits';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { parseISO } from 'date-fns';
import { Check, Plus, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface SplitTransactionDialogProps {
    transaction: DecryptedTransaction | null;
    categories: Category[];
    labels: Label[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess: () => void;
    onLabelCreated?: (label: Label) => void;
}

/** The parts start out empty, so the dialog opens on "nothing handed out yet". */
function emptyDraft(labelIds: string[]): SplitDraft {
    return {
        key: crypto.randomUUID(),
        categoryId: null,
        amount: 0,
        labelIds,
    };
}

export function SplitTransactionDialog({
    transaction,
    categories,
    labels,
    open,
    onOpenChange,
    onSuccess,
    onLabelCreated,
}: SplitTransactionDialogProps) {
    const locale = useLocale();
    const [drafts, setDrafts] = useState<SplitDraft[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (!open || !transaction) {
            return;
        }

        // Every part inherits the labels the whole transaction carried, so
        // nothing is silently dropped — they can be taken off part by part.
        const inheritedLabels = transaction.label_ids ?? [];

        setDrafts([emptyDraft(inheritedLabels), emptyDraft(inheritedLabels)]);
    }, [open, transaction]);

    if (!transaction) {
        return null;
    }

    const totalCents = transaction.amount;
    const currencyCode = transaction.currency_code;
    const remaining = remainingCents(totalCents, drafts);
    const isBalanced = isSplitBalanced(totalCents, drafts);
    const isExpense = totalCents < 0;

    const patchDraft = (key: string, changes: Partial<SplitDraft>) => {
        setDrafts((previous) =>
            previous.map((draft) =>
                draft.key === key ? { ...draft, ...changes } : draft,
            ),
        );
    };

    const addDraft = () => {
        setDrafts((previous) => [...previous, emptyDraft([])]);
    };

    const removeDraft = (key: string) => {
        setDrafts((previous) =>
            previous.length > 2
                ? previous.filter((draft) => draft.key !== key)
                : previous,
        );
    };

    /** Hand whatever is left to the last part, so the common case is one click. */
    const assignRemainder = () => {
        setDrafts((previous) =>
            previous.map((draft, index) =>
                index === previous.length - 1
                    ? {
                          ...draft,
                          amount: Math.max(0, draft.amount + remaining),
                      }
                    : draft,
            ),
        );
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();

        if (!isBalanced || isSubmitting) {
            return;
        }

        setIsSubmitting(true);

        try {
            await transactionSyncService.split(
                transaction.id,
                toSplitPayload(totalCents, drafts),
            );

            toast.success(
                __('Split into :count transactions.', { count: drafts.length }),
            );
            onOpenChange(false);
            onSuccess();
        } catch (error) {
            console.error('Failed to split transaction:', error);
            toast.error(__('The transaction could not be split.'));
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>{__('Split transaction')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Share the amount out between several transactions, so each part gets its own category and labels.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit}>
                    <div className="rounded-md border border-border bg-muted/40 p-3 text-sm">
                        <div className="flex items-center justify-between gap-3">
                            <span className="truncate font-medium">
                                {transaction.decryptedDescription}
                            </span>
                            <span className="shrink-0 font-mono font-medium tabular-nums">
                                {formatCurrency(
                                    totalCents,
                                    currencyCode,
                                    locale,
                                )}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {formatDate(
                                parseISO(transaction.transaction_date),
                                'PPP',
                                locale,
                            )}
                            {' · '}
                            {__('kept as the original')}
                        </p>
                    </div>

                    <div className="flex flex-col gap-3 py-4">
                        {drafts.map((draft, index) => (
                            <div
                                key={draft.key}
                                className="flex flex-col gap-1.5"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="w-4 shrink-0 text-right text-xs text-muted-foreground">
                                        {index + 1}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <CategorySelect
                                            value={draft.categoryId ?? 'null'}
                                            onValueChange={(value) =>
                                                patchDraft(draft.key, {
                                                    categoryId:
                                                        value === 'null'
                                                            ? null
                                                            : value,
                                                })
                                            }
                                            categories={categories}
                                            disabled={isSubmitting}
                                            placeholder={__('Uncategorized')}
                                            triggerClassName="w-full"
                                            showUncategorized={true}
                                        />
                                    </div>
                                    <div className="flex w-[150px] shrink-0 items-center gap-1">
                                        {isExpense && (
                                            <span
                                                aria-hidden="true"
                                                className="text-sm text-muted-foreground"
                                            >
                                                −
                                            </span>
                                        )}
                                        <AmountInput
                                            value={draft.amount}
                                            onChange={(valueInCents) =>
                                                patchDraft(draft.key, {
                                                    amount: Math.abs(
                                                        valueInCents,
                                                    ),
                                                })
                                            }
                                            currencyCode={currencyCode}
                                            disabled={isSubmitting}
                                            placeholder="0.00"
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        onClick={() => removeDraft(draft.key)}
                                        disabled={
                                            isSubmitting || drafts.length <= 2
                                        }
                                        className={
                                            drafts.length <= 2
                                                ? 'invisible'
                                                : undefined
                                        }
                                        aria-label={__('Remove split')}
                                    >
                                        <X />
                                    </Button>
                                </div>
                                <div className="pl-6">
                                    <LabelCombobox
                                        value={draft.labelIds}
                                        onValueChange={(labelIds) =>
                                            patchDraft(draft.key, { labelIds })
                                        }
                                        labels={labels}
                                        disabled={isSubmitting}
                                        placeholder={__('Add labels...')}
                                        triggerClassName="min-h-8 py-1"
                                        onLabelCreated={onLabelCreated}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={addDraft}
                            disabled={isSubmitting || drafts.length >= 20}
                            className="self-start"
                        >
                            <Plus />
                            {__('Add split')}
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            {isExpense
                                ? __(
                                      'Every split is money out, just like the original.',
                                  )
                                : __(
                                      'Every split is money in, just like the original.',
                                  )}
                        </p>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4 text-sm">
                        {isBalanced ? (
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                <Check className="size-4" />
                                {__('All shared out')}
                            </span>
                        ) : (
                            <span className="flex items-center gap-1.5 font-medium text-destructive">
                                <TriangleAlert className="size-4" />
                                {remaining === 0
                                    ? __('Every split needs an amount')
                                    : remaining > 0
                                      ? __('Still to share out: :amount', {
                                            amount: formatCurrency(
                                                remaining,
                                                currencyCode,
                                                locale,
                                            ),
                                        })
                                      : __('Over by :amount', {
                                            amount: formatCurrency(
                                                -remaining,
                                                currencyCode,
                                                locale,
                                            ),
                                        })}
                            </span>
                        )}
                        {remaining !== 0 && (
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                onClick={assignRemainder}
                                disabled={isSubmitting}
                                className="h-auto p-0"
                            >
                                {__('Give the rest to split :number', {
                                    number: drafts.length,
                                })}
                            </Button>
                        )}
                    </div>

                    <DialogFooter className="mt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={isSubmitting || !isBalanced}
                            data-testid="submit-split"
                        >
                            {isSubmitting
                                ? __('Splitting...')
                                : __('Split into :count', {
                                      count: drafts.length,
                                  })}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
