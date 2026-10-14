import { update } from '@/actions/App/Http/Controllers/SavingsGoalController';
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
import { Label as UILabel } from '@/components/ui/label';
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import {
    isMonthlyTargetValid,
    MonthlyTargetFields,
    monthlyTargetPayload,
    MonthlyTargetValue,
    ReminderField,
} from './monthly/monthly-goal-fields';

function monthlyTargetOf(savingsGoal: SavingsGoal): MonthlyTargetValue {
    return {
        type: savingsGoal.monthly_target_type ?? 'amount',
        amount: savingsGoal.monthly_target_amount ?? 0,
        rate:
            savingsGoal.monthly_target_rate === null
                ? ''
                : String(savingsGoal.monthly_target_rate),
    };
}

interface Props {
    savingsGoal: SavingsGoal;
    currencyCode: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function EditSavingsGoalDialog({
    savingsGoal,
    currencyCode,
    open,
    onOpenChange,
}: Props) {
    const [name, setName] = useState(savingsGoal.name);
    const [targetAmount, setTargetAmount] = useState<number>(
        savingsGoal.target_amount,
    );
    const [initialAmount, setInitialAmount] = useState<number>(
        savingsGoal.initial_amount,
    );
    const [targetDate, setTargetDate] = useState<string>(
        savingsGoal.target_date ?? '',
    );
    const [monthlyTarget, setMonthlyTarget] = useState<MonthlyTargetValue>(
        monthlyTargetOf(savingsGoal),
    );
    const [notifyReminder, setNotifyReminder] = useState(
        savingsGoal.notify_on_month_end_reminder,
    );
    const isMonthly = savingsGoal.kind === 'monthly';
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (open) {
            setName(savingsGoal.name);
            setTargetAmount(savingsGoal.target_amount);
            setInitialAmount(savingsGoal.initial_amount);
            setTargetDate(savingsGoal.target_date ?? '');
            setMonthlyTarget(monthlyTargetOf(savingsGoal));
            setNotifyReminder(savingsGoal.notify_on_month_end_reminder);
            setErrors({});
        }
    }, [open, savingsGoal]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});
        setIsSubmitting(true);

        router.patch(
            update({ savingsGoal: savingsGoal.id }).url,
            isMonthly
                ? {
                      name,
                      ...monthlyTargetPayload(monthlyTarget),
                      notify_on_month_end_reminder: notifyReminder,
                  }
                : {
                      name,
                      target_amount: targetAmount,
                      initial_amount: initialAmount,
                      target_date: targetDate || null,
                  },
            {
                onSuccess: () => onOpenChange(false),
                onError: (formErrors) =>
                    setErrors(formErrors as Record<string, string>),
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[500px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>{__('Edit Savings Goal')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Renaming the goal also renames its label on your transactions.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-6 py-4">
                        <div className="space-y-2">
                            <UILabel htmlFor="edit-goal-name">
                                {__('Goal Name')}
                            </UILabel>
                            <Input
                                id="edit-goal-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                required
                            />
                            {errors.name && (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        {isMonthly ? (
                            <>
                                <MonthlyTargetFields
                                    idPrefix="edit-goal"
                                    value={monthlyTarget}
                                    onChange={setMonthlyTarget}
                                    currencyCode={currencyCode}
                                    errors={errors}
                                />
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'A new target applies from this month on. Past months keep the target they had.',
                                    )}
                                </p>
                                <ReminderField
                                    id="edit-goal-reminder"
                                    checked={notifyReminder}
                                    onChange={setNotifyReminder}
                                />
                            </>
                        ) : (
                            <>
                                <div className="space-y-2">
                                    <UILabel htmlFor="edit-goal-target">
                                        {__('Target Amount')}
                                    </UILabel>
                                    <AmountInput
                                        id="edit-goal-target"
                                        value={targetAmount}
                                        onChange={setTargetAmount}
                                        currencyCode={currencyCode}
                                    />
                                    {errors.target_amount && (
                                        <p className="text-sm text-destructive">
                                            {errors.target_amount}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <UILabel htmlFor="edit-goal-initial">
                                        {__('Already Saved')}{' '}
                                        <span className="text-muted-foreground">
                                            {__('(optional)')}
                                        </span>
                                    </UILabel>
                                    <AmountInput
                                        id="edit-goal-initial"
                                        value={initialAmount}
                                        onChange={setInitialAmount}
                                        currencyCode={currencyCode}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'What you had already put aside before creating this goal. Linked transactions add on top of it.',
                                        )}
                                    </p>
                                    {errors.initial_amount && (
                                        <p className="text-sm text-destructive">
                                            {errors.initial_amount}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <UILabel htmlFor="edit-goal-target-date">
                                        {__('Target Date')}{' '}
                                        <span className="text-muted-foreground">
                                            {__('(optional)')}
                                        </span>
                                    </UILabel>
                                    <Input
                                        id="edit-goal-target-date"
                                        type="date"
                                        min="1900-01-01"
                                        max="2100-01-01"
                                        value={targetDate}
                                        onChange={(e) =>
                                            setTargetDate(e.target.value)
                                        }
                                    />
                                    {errors.target_date && (
                                        <p className="text-sm text-destructive">
                                            {errors.target_date}
                                        </p>
                                    )}
                                </div>
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                isSubmitting ||
                                !name ||
                                (isMonthly
                                    ? !isMonthlyTargetValid(monthlyTarget)
                                    : targetAmount <= 0)
                            }
                        >
                            {isSubmitting
                                ? __('Saving...')
                                : __('Save changes')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
