import { update as updateLoanDetail } from '@/actions/App/Http/Controllers/LoanDetailController';
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
import type { CurrencyCode, LoanDetail } from '@/types/account';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface EditLoanDetailDialogProps {
    loanAccountId: string;
    currencyCode: CurrencyCode;
    detail: LoanDetail | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function EditLoanDetailDialog({
    loanAccountId,
    currencyCode,
    detail,
    open,
    onOpenChange,
}: EditLoanDetailDialogProps) {
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formData, setFormData] = useState({
        annual_interest_rate: detail?.annual_interest_rate ?? '',
        loan_term_months: detail?.loan_term_months
            ? String(detail.loan_term_months)
            : '',
        start_date: detail?.start_date?.slice(0, 10) ?? '',
        original_amount: detail?.original_amount ?? 0,
    });

    useEffect(() => {
        if (open) {
            setFormData({
                annual_interest_rate: detail?.annual_interest_rate ?? '',
                loan_term_months: detail?.loan_term_months
                    ? String(detail.loan_term_months)
                    : '',
                start_date: detail?.start_date?.slice(0, 10) ?? '',
                original_amount: detail?.original_amount ?? 0,
            });
            setErrors({});
        }
    }, [open, detail]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setIsSubmitting(true);
        setErrors({});

        router.patch(
            updateLoanDetail.url(loanAccountId),
            {
                annual_interest_rate: formData.annual_interest_rate,
                loan_term_months: Number(formData.loan_term_months),
                start_date: formData.start_date,
                original_amount: formData.original_amount,
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => setErrors(errors),
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>
                        {detail
                            ? __('Edit Loan Details')
                            : __('Add Loan Details')}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'Set interest rate, term, and amount to track amortization.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="dialog_annual_interest_rate">
                                {__('Annual Interest Rate (%)')}
                            </Label>
                            <Input
                                id="dialog_annual_interest_rate"
                                type="number"
                                value={formData.annual_interest_rate}
                                onChange={(e) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        annual_interest_rate: e.target.value,
                                    }))
                                }
                                placeholder="3.500"
                                min="0"
                                max="99.999"
                                step="0.001"
                            />
                            <InputError message={errors.annual_interest_rate} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="dialog_loan_term_months">
                                {__('Loan Term (months)')}
                            </Label>
                            <Input
                                id="dialog_loan_term_months"
                                type="number"
                                value={formData.loan_term_months}
                                onChange={(e) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        loan_term_months: e.target.value,
                                    }))
                                }
                                placeholder="360"
                                min="1"
                                max="600"
                            />
                            <InputError message={errors.loan_term_months} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="dialog_loan_start_date">
                                {__('Start Date')}
                            </Label>
                            <Input
                                id="dialog_loan_start_date"
                                type="date"
                                value={formData.start_date}
                                onChange={(e) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        start_date: e.target.value,
                                    }))
                                }
                            />
                            <InputError message={errors.start_date} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="dialog_original_amount">
                                {__('Original Amount')}
                            </Label>
                            <AmountInput
                                id="dialog_original_amount"
                                value={formData.original_amount}
                                onChange={(value) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        original_amount: value,
                                    }))
                                }
                                currencyCode={currencyCode}
                            />
                            <InputError message={errors.original_amount} />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={isSubmitting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            {isSubmitting ? __('Saving...') : __('Save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
