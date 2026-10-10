import {
    destroy as destroyCreditCardDetail,
    update as updateCreditCardDetail,
} from '@/actions/App/Http/Controllers/CreditCardDetailController';
import InputError from '@/components/input-error';
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
import type { CreditCardDetail, CurrencyCode } from '@/types/account';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface EditCreditCardDetailDialogProps {
    accountId: string;
    currencyCode: CurrencyCode;
    detail: CreditCardDetail | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type DateField = 'statement_closing_date' | 'payment_due_date';

/** The dates as the inputs hold them: an empty string while unset. */
type DateValues = Record<DateField, string>;

/** Mirrors PaymentDueAfterStatementClosing::MAX_DAYS_TO_PAY on the server. */
const MAX_DAYS_TO_PAY = 45;

function datesOf(detail: CreditCardDetail | null): DateValues {
    return {
        statement_closing_date: detail?.statement_closing_date ?? '',
        payment_due_date: detail?.payment_due_date ?? '',
    };
}

/**
 * Sets a card's credit limit and the two dates its statement estimate is
 * projected from, each optional. Any closing date and its due date will do:
 * later cycles repeat monthly from them, and editing them when the bank moves
 * the dates sets a new anchor.
 */
export function EditCreditCardDetailDialog({
    accountId,
    currencyCode,
    detail,
    open,
    onOpenChange,
}: EditCreditCardDetailDialogProps) {
    const [dates, setDates] = useState<DateValues>(datesOf(detail));
    const [creditLimit, setCreditLimit] = useState<number | null>(
        detail?.credit_limit ?? null,
    );
    const [errors, setErrors] = useState<
        Partial<Record<DateField | 'credit_limit', string>>
    >({});
    const [isSubmitting, setIsSubmitting] = useState(false);
    const hasDates = Boolean(
        detail?.statement_closing_date && detail.payment_due_date,
    );

    useEffect(() => {
        if (open) {
            setDates(datesOf(detail));
            setCreditLimit(detail?.credit_limit ?? null);
            setErrors({});
        }
    }, [open, detail]);

    const visitOptions = {
        preserveScroll: true,
        onStart: () => setIsSubmitting(true),
        onSuccess: () => onOpenChange(false),
        onError: (validationErrors: Record<string, string>) =>
            setErrors(validationErrors),
        onFinish: () => setIsSubmitting(false),
    };

    function handleSubmit(event: React.FormEvent) {
        event.preventDefault();
        router.patch(
            updateCreditCardDetail.url(accountId),
            {
                statement_closing_date: dates.statement_closing_date || null,
                payment_due_date: dates.payment_due_date || null,
                credit_limit: creditLimit,
            },
            visitOptions,
        );
    }

    function handleRemove() {
        router.delete(destroyCreditCardDetail.url(accountId), visitOptions);
    }

    const fields: { name: DateField; label: string; hint: string }[] = [
        {
            name: 'statement_closing_date',
            label: __('Statement closing date'),
            hint: __(
                'Any closing date of this card, past or upcoming. Later ones are projected from it.',
            ),
        },
        {
            name: 'payment_due_date',
            label: __('Payment due date'),
            hint: __(
                'The day that statement is charged, at most :days days after it closes.',
                { days: MAX_DAYS_TO_PAY },
            ),
        },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Card details')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Every field is optional. Later statements are assumed to repeat on the same days every month. If your bank moves them, update them here.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="credit_card_credit_limit">
                            {__('Credit limit')}
                        </Label>
                        <AmountInput
                            id="credit_card_credit_limit"
                            value={creditLimit ?? 0}
                            placeholder={__('No limit set')}
                            onChange={(valueInCents) =>
                                setCreditLimit(
                                    valueInCents > 0 ? valueInCents : null,
                                )
                            }
                            currencyCode={currencyCode}
                        />
                        <p className="text-xs text-muted-foreground">
                            {__(
                                'The most your bank lets you spend on this card.',
                            )}
                        </p>
                        <InputError message={errors.credit_limit} />
                    </div>

                    {fields.map((field) => (
                        <div key={field.name} className="flex flex-col gap-2">
                            <Label htmlFor={`credit_card_${field.name}`}>
                                {field.label}
                            </Label>
                            <Input
                                id={`credit_card_${field.name}`}
                                type="date"
                                value={dates[field.name]}
                                onChange={(event) =>
                                    setDates((previous) => ({
                                        ...previous,
                                        [field.name]: event.target.value,
                                    }))
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {field.hint}
                            </p>
                            <InputError message={errors[field.name]} />
                        </div>
                    ))}

                    <DialogFooter className="gap-2 sm:justify-between">
                        {hasDates ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                onClick={handleRemove}
                                disabled={isSubmitting}
                            >
                                {__('Remove dates')}
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? __('Saving...') : __('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
