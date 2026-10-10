import { AmountDisplay } from '@/components/ui/amount-display';
import { Progress } from '@/components/ui/progress';
import {
    daysLeftLabel,
    formatRate,
    isTargetUnknown,
    progressPercent,
} from '@/lib/monthly-savings';
import { cn } from '@/lib/utils';
import {
    MonthlySavingsCurrent,
    MonthlySavingsMonth,
    SavingsGoal,
} from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { Fragment, ReactNode } from 'react';
import { differenceClassName } from './month-status';

/**
 * A translated sentence with a component in place of `:amount`, so the amount
 * still goes through AmountDisplay (and privacy mode) while each language puts
 * it where its grammar wants: "€180 to go", "Faltan 180 €".
 */
export function withAmount(template: string, amount: ReactNode): ReactNode {
    const [before, ...rest] = template.split(':amount');

    return (
        <>
            {before}
            {rest.length > 0 && (
                <>
                    {amount}
                    {rest.map((part, index) => (
                        <Fragment key={index}>{part}</Fragment>
                    ))}
                </>
            )}
        </>
    );
}

/** What is still missing to reach a month's target. */
export function AmountToGo({
    amount,
    currencyCode,
}: {
    amount: number;
    currencyCode: string;
}) {
    return withAmount(
        __(':amount to go'),
        <AmountDisplay amountInCents={amount} currencyCode={currencyCode} />,
    );
}

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
 * how many days. A partial month — the goal started in its last days — only
 * shows what was saved: it has no target to measure against.
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
    if (current.status === 'partial') {
        return (
            <div className="flex flex-col gap-2">
                <AmountDisplay
                    amountInCents={current.saved}
                    currencyCode={currencyCode}
                    size={size === 'xl' ? '2xl' : 'xl'}
                    weight="semibold"
                    className="tabular-nums"
                />
                <span className="text-sm text-muted-foreground">
                    {__(
                        'Partial month: you started at the end of the month. The first verdict comes next month.',
                    )}
                </span>
            </div>
        );
    }

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
            <MonthlyGoalProgress
                saved={current.saved}
                target={current.target}
                empty={isTargetUnknown(current)}
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
    // A share of an income that has not arrived yet is 0, which is not a
    // target met: say why it is empty instead.
    if (current.is_live_target && current.target <= 0) {
        return (
            <span className="text-sm text-muted-foreground">
                {__('the target grows as income comes in')}
            </span>
        );
    }

    if (current.remaining <= 0) {
        return (
            <span className="text-sm text-emerald-700 dark:text-emerald-400">
                {__('Target met for this month')} ·{' '}
                {daysLeftLabel(current.days_left)}
            </span>
        );
    }

    return (
        <span className="text-sm text-muted-foreground">
            <AmountToGo
                amount={current.remaining}
                currencyCode={currencyCode}
            />{' '}
            ·{' '}
            {current.is_live_target
                ? __('the target grows as income comes in')
                : daysLeftLabel(current.days_left)}
        </span>
    );
}

/** "€120 of €300", the saved figure first and larger. */
export function SavedOfTarget({
    saved,
    target,
    currencyCode,
}: {
    saved: number;
    target: number;
    currencyCode: string;
}) {
    return (
        <span className="text-2xl font-semibold tabular-nums">
            <AmountDisplay amountInCents={saved} currencyCode={currencyCode} />{' '}
            <span className="text-sm font-normal text-muted-foreground">
                {__('of')}{' '}
                <AmountDisplay
                    amountInCents={target}
                    currencyCode={currencyCode}
                />
            </span>
        </span>
    );
}

/**
 * The green bar every monthly goal is drawn with. `empty` when the target is
 * not known yet (a partial month, or a live share of income still at 0), so
 * a target of 0 does not draw as a full bar.
 */
export function MonthlyGoalProgress({
    saved,
    target,
    empty = false,
    className = 'h-2',
}: {
    saved: number;
    target: number;
    empty?: boolean;
    className?: string;
}) {
    return (
        <Progress
            value={empty ? 0 : progressPercent(saved, target)}
            className={className}
            indicatorClassName="bg-emerald-600 dark:bg-emerald-500"
        />
    );
}
