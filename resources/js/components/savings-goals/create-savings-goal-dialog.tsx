import { store } from '@/actions/App/Http/Controllers/SavingsGoalController';
import { CreatePlaceholderCard } from '@/components/shared/create-placeholder-card';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label as UILabel } from '@/components/ui/label';
import { useControllableOpen } from '@/hooks/use-controllable-open';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import React, { useState } from 'react';

interface Props {
    className?: string;
    currencyCode?: string;
    trigger?: React.ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

export function CreateSavingsGoalDialog({
    className = '',
    currencyCode = 'USD',
    trigger,
    open,
    onOpenChange,
}: Props) {
    const {
        open: dialogOpen,
        setOpen: setDialogOpen,
        isControlled,
    } = useControllableOpen({ open, onOpenChange });

    const [name, setName] = useState('');
    const [targetAmount, setTargetAmount] = useState<number>(0);
    const [initialAmount, setInitialAmount] = useState<number>(0);
    const [targetDate, setTargetDate] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const today = new Date().toISOString().slice(0, 10);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});
        setIsSubmitting(true);

        router.post(
            store().url,
            {
                name,
                target_amount: targetAmount,
                initial_amount: initialAmount,
                target_date: targetDate || null,
            },
            {
                onSuccess: () => {
                    setName('');
                    setTargetAmount(0);
                    setInitialAmount(0);
                    setTargetDate('');
                    setErrors({});
                    setDialogOpen(false);
                },
                onError: (formErrors) => {
                    setErrors(formErrors as Record<string, string>);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            {trigger !== undefined ? (
                <DialogTrigger asChild>{trigger}</DialogTrigger>
            ) : isControlled ? null : (
                <DialogTrigger asChild>
                    <CreatePlaceholderCard className={className}>
                        {__('Create Savings Goal')}
                    </CreatePlaceholderCard>
                </DialogTrigger>
            )}
            <DialogContent className="sm:max-w-[500px]">
                <form onSubmit={handleSubmit}>
                    <DialogHeader>
                        <DialogTitle>{__('Create Savings Goal')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'Set a target to save toward. Tag transactions with the goal’s label to track your progress.',
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-6 py-4">
                        <div className="space-y-2">
                            <UILabel htmlFor="goal-name">
                                {__('Goal Name')}
                            </UILabel>
                            <Input
                                id="goal-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder={__('e.g., New car')}
                                required
                            />
                            {errors.name && (
                                <p className="text-sm text-destructive">
                                    {errors.name}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <UILabel htmlFor="goal-target">
                                {__('Target Amount')}
                            </UILabel>
                            <AmountInput
                                id="goal-target"
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
                            <UILabel htmlFor="goal-initial">
                                {__('Already Saved')}{' '}
                                <span className="text-muted-foreground">
                                    {__('(optional)')}
                                </span>
                            </UILabel>
                            <AmountInput
                                id="goal-initial"
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
                            <UILabel htmlFor="goal-target-date">
                                {__('Target Date')}{' '}
                                <span className="text-muted-foreground">
                                    {__('(optional)')}
                                </span>
                            </UILabel>
                            <Input
                                id="goal-target-date"
                                type="date"
                                min={today}
                                value={targetDate}
                                onChange={(e) => setTargetDate(e.target.value)}
                            />
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'When you’d like to reach 100% of your goal.',
                                )}
                            </p>
                            {errors.target_date && (
                                <p className="text-sm text-destructive">
                                    {errors.target_date}
                                </p>
                            )}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDialogOpen(false)}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                isSubmitting || !name || targetAmount <= 0
                            }
                        >
                            {isSubmitting
                                ? __('Creating...')
                                : __('Create Savings Goal')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
