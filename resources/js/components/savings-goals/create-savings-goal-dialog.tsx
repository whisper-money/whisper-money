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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useControllableOpen } from '@/hooks/use-controllable-open';
import { useLocale } from '@/hooks/use-locale';
import { Account } from '@/types/account';
import { SavingsGoalKind } from '@/types/savings-goal';
import { formatMonthYear, todayDateString } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import React, { useMemo, useState } from 'react';
import {
    AutoTagFields,
    isMonthlyTargetValid,
    MonthlyTargetFields,
    monthlyTargetPayload,
    MonthlyTargetValue,
    ReminderField,
} from './monthly/monthly-goal-fields';

const EMPTY_MONTHLY_TARGET: MonthlyTargetValue = {
    type: 'amount',
    amount: 0,
    rate: '',
};

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

    const locale = useLocale();
    const { accounts = [] } = usePage<{ accounts?: Account[] }>().props;
    const savingsAccounts = useMemo(
        () =>
            accounts.filter(
                (account) => account.type === 'savings' && !account.archived_at,
            ),
        [accounts],
    );

    const [kind, setKind] = useState<SavingsGoalKind>('one_off');
    const [monthlyTarget, setMonthlyTarget] =
        useState<MonthlyTargetValue>(EMPTY_MONTHLY_TARGET);
    // On by default when there is somewhere to point it.
    const [autoTag, setAutoTag] = useState(true);
    const [autoTagAccountId, setAutoTagAccountId] = useState('');
    const [notifyReminder, setNotifyReminder] = useState(true);
    const [name, setName] = useState('');
    const [targetAmount, setTargetAmount] = useState<number>(0);
    const [initialAmount, setInitialAmount] = useState<number>(0);
    const [targetDate, setTargetDate] = useState<string>('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const today = todayDateString();
    const isMonthly = kind === 'monthly';
    const tagAccountId = autoTagAccountId || savingsAccounts[0]?.id || '';

    const payload = () =>
        isMonthly
            ? {
                  name,
                  kind,
                  ...monthlyTargetPayload(monthlyTarget),
                  notify_on_month_end_reminder: notifyReminder,
                  auto_tag_account_id:
                      autoTag && tagAccountId ? tagAccountId : null,
              }
            : {
                  name,
                  kind,
                  target_amount: targetAmount,
                  initial_amount: initialAmount,
                  target_date: targetDate || null,
              };

    const reset = () => {
        setKind('one_off');
        setMonthlyTarget(EMPTY_MONTHLY_TARGET);
        setAutoTag(true);
        setAutoTagAccountId('');
        setNotifyReminder(true);
        setName('');
        setTargetAmount(0);
        setInitialAmount(0);
        setTargetDate('');
        setErrors({});
    };

    const isValid =
        !!name &&
        (isMonthly ? isMonthlyTargetValid(monthlyTarget) : targetAmount > 0);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});
        setIsSubmitting(true);

        router.post(store().url, payload(), {
            onSuccess: () => {
                reset();
                setDialogOpen(false);
            },
            onError: (formErrors) => {
                setErrors(formErrors as Record<string, string>);
            },
            onFinish: () => setIsSubmitting(false),
        });
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
                            <UILabel>{__('Type')}</UILabel>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={kind}
                                onValueChange={(value) =>
                                    value && setKind(value as SavingsGoalKind)
                                }
                                aria-label={__('Type')}
                                className="grid w-full grid-cols-2"
                            >
                                <ToggleGroupItem value="one_off">
                                    {__('One-off (with a target)')}
                                </ToggleGroupItem>
                                <ToggleGroupItem value="monthly">
                                    {__('Monthly (recurring)')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>

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

                        {isMonthly ? (
                            <>
                                <MonthlyTargetFields
                                    idPrefix="goal"
                                    value={monthlyTarget}
                                    onChange={setMonthlyTarget}
                                    currencyCode={currencyCode}
                                    errors={errors}
                                />
                                <AutoTagFields
                                    enabled={autoTag}
                                    onEnabledChange={setAutoTag}
                                    accountId={tagAccountId}
                                    onAccountChange={setAutoTagAccountId}
                                    savingsAccounts={savingsAccounts}
                                    error={errors.auto_tag_account_id}
                                />
                                <ReminderField
                                    id="goal-reminder"
                                    checked={notifyReminder}
                                    onChange={setNotifyReminder}
                                />
                                <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                                    {__(
                                        'Starts in :month. Each month is judged against the target in force that month.',
                                        {
                                            month: formatMonthYear(
                                                new Date(),
                                                locale,
                                            ),
                                        },
                                    )}
                                </p>
                            </>
                        ) : (
                            <>
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
                                        max="2100-01-01"
                                        value={targetDate}
                                        onChange={(e) =>
                                            setTargetDate(e.target.value)
                                        }
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
                            </>
                        )}
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
                            disabled={isSubmitting || !isValid}
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
