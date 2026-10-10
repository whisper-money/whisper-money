import { AmountDisplay } from '@/components/ui/amount-display';
import { Progress } from '@/components/ui/progress';
import { progressPercent } from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import {
    MonthlySavingsCurrent,
    MonthlySavingsMonth,
    SavingsGoal,
} from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { differenceClassName } from './month-status';

/** "300 € a month" or "20% of income": what the goal asks for. */
export function MonthlyTargetRule({
    goal,
    currencyCode,
}: {
    goal: SavingsGoal;
    currencyCode: string;
}) {
    if (goal.monthly_target_type === 'income_rate') {
        return (
            <>
                {__(':rate% of your income', {
                    rate: formatRate(goal.monthly_target_rate),
                })}
            </>
        );
    }

    return (
        <>
            <AmountDisplay
                amountInCents={goal.monthly_target_amount ?? 0}
                currencyCode={currencyCode}
            />{' '}
            {__('a month')}
        </>
    );
}

export function formatRate(rate: number | null): string {
    return String(Number(rate ?? 0));
}

/**
 * "480 € (20% of 2,400 €)": a share-of-income target spells out the income it
 * was worked out from, so the figure is never a mystery.
 */
export function MonthTarget({
    month,
    currencyCode,
}: {
    month: MonthlySavingsMonth;
    currencyCode: string;
}) {
    return (
        <>
            <AmountDisplay
                amountInCents={month.target}
                currencyCode={currencyCode}
            />
            {month.target_type === 'income_rate' &&
                month.income_base !== null && (
                    <>
                        {' '}
                        (
                        {__(':rate% of', {
                            rate: formatRate(month.target_rate),
                        })}{' '}
                        <AmountDisplay
                            amountInCents={month.income_base}
                            currencyCode={currencyCode}
                        />
                        )
                    </>
                )}
        </>
    );
}

/** A signed amount, green when ahead and orange when behind. */
export function SignedAmount({
    amount,
    currencyCode,
    className,
}: {
    amount: number;
    currencyCode: string;
    className?: string;
}) {
    return (
        <AmountDisplay
            amountInCents={amount}
            currencyCode={currencyCode}
            showSign
            className={cn(
                'font-semibold tabular-nums',
                differenceClassName(amount),
                className,
            )}
        />
    );
}

/**
 * The month in progress: saved of target, a bar, and what is left to do in
 * how many days.
 */
export function CurrentMonthProgress({
    current,
    currencyCode,
    size = 'lg',
}: {
    current: MonthlySavingsCurrent;
    currencyCode: string;
    size?: 'lg' | 'xl';
}) {
    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2 tabular-nums">
                <AmountDisplay
                    amountInCents={current.saved}
                    currencyCode={currencyCode}
                    size={size === 'xl' ? '2xl' : 'xl'}
                    weight="semibold"
                />
                <span className="text-sm text-muted-foreground">
                    {__('of')}{' '}
                    <MonthTarget month={current} currencyCode={currencyCode} />
                </span>
            </div>
            <Progress
                value={progressPercent(current.saved, current.target)}
                className="h-2"
                indicatorClassName="bg-emerald-600 dark:bg-emerald-500"
            />
            <RemainingLine current={current} currencyCode={currencyCode} />
        </div>
    );
}

function RemainingLine({
    current,
    currencyCode,
}: {
    current: MonthlySavingsCurrent;
    currencyCode: string;
}) {
    const daysLeft = __(':count days left', { count: current.days_left });

    if (current.remaining <= 0) {
        return (
            <span className="text-sm text-emerald-700 dark:text-emerald-400">
                {__('Target met for this month')} · {daysLeft}
            </span>
        );
    }

    return (
        <span className="text-sm text-muted-foreground">
            <AmountDisplay
                amountInCents={current.remaining}
                currencyCode={currencyCode}
            />{' '}
            {__('to go')} ·{' '}
            {current.is_live_target
                ? __('the target grows as income comes in')
                : daysLeft}
        </span>
    );
}
