import {
    destroy as destroyCreditCardDetail,
    update as updateCreditCardDetail,
} from '@/actions/App/Http/Controllers/CreditCardDetailController';
import InputError from '@/components/input-error';
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
import type { CreditCardDetail } from '@/types/account';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface EditCreditCardDetailDialogProps {
    accountId: string;
    detail: CreditCardDetail | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type DateField = keyof CreditCardDetail;

const EMPTY_DATES: CreditCardDetail = {
    statement_closing_date: '',
    payment_due_date: '',
};

/**
 * Sets the two dates a card's statement estimate is projected from. Any
 * closing date and its due date will do: later cycles repeat monthly from
 * them, and editing them when the bank moves the dates sets a new anchor.
 */
export function EditCreditCardDetailDialog({
    accountId,
    detail,
    open,
    onOpenChange,
}: EditCreditCardDetailDialogProps) {
    const [dates, setDates] = useState<CreditCardDetail>(detail ?? EMPTY_DATES);
    const [errors, setErrors] = useState<Partial<Record<DateField, string>>>(
        {},
    );
    const [isSubmitting, setIsSubmitting] = useState(false);

    useEffect(() => {
        if (open) {
            setDates(detail ?? EMPTY_DATES);
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
            { ...dates },
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
            hint: __('The day your next (or last) statement closes.'),
        },
        {
            name: 'payment_due_date',
            label: __('Payment due date'),
            hint: __('The day that statement is charged.'),
        },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Statement dates')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Later statements are assumed to repeat on the same days every month. If your bank moves them, update them here.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                    {fields.map((field) => (
                        <div key={field.name} className="flex flex-col gap-2">
                            <Label htmlFor={`credit_card_${field.name}`}>
                                {field.label}
                            </Label>
                            <Input
                                id={`credit_card_${field.name}`}
                                type="date"
                                required
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
                        {detail ? (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                onClick={handleRemove}
                                disabled={isSubmitting}
                            >
                                {__('Remove')}
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
