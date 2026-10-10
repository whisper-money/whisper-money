import { AmountDisplay } from '@/components/ui/amount-display';
import { useLocale } from '@/hooks/use-locale';
import {
    aggregateMonthlyGoals,
    currentMonthOf,
    daysLeftLabel,
    monthDate,
} from '@/lib/monthly-savings';
import { MonthlyVerdicts, SavingsGoal } from '@/types/savings-goal';
import { formatDate, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Repeat } from 'lucide-react';
import { legendStatuses, MonthStatusLegend } from './month-status';
import { MonthlySavingsGoalCard } from './monthly-savings-goal-card';

interface Props {
    goals: SavingsGoal[];
    /** How every goal, archived ones included, did last month. */
    lastMonth: MonthlyVerdicts | null;
    currencyCode: string;
}

/**
 * The Planning page's "Monthly savings" block: this month across every running
 * monthly goal, then one card per goal. It sits above the reorderable list on
 * purpose — a goal that starts over every month is not ranked against budgets.
 */
export function MonthlySavingsSection({
    goals,
    lastMonth,
    currencyCode,
}: Props) {
    const locale = useLocale();
    const currentMonth = currentMonthOf(goals);
    const aggregate = aggregateMonthlyGoals(goals);

    return (
        <section
            aria-labelledby="monthly-savings-heading"
            className="flex flex-col gap-4"
        >
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h2
                        id="monthly-savings-heading"
                        className="flex items-center gap-2 text-lg font-semibold"
                    >
                        <Repeat className="size-4" />
                        {__('Monthly savings')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {formatMonthYear(monthDate(currentMonth), locale)}
                        {aggregate.daysLeft !== null &&
                            ` · ${daysLeftLabel(aggregate.daysLeft)}`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-6 text-sm">
                    <div className="flex flex-col gap-0.5">
                        <span className="text-muted-foreground">
                            {__('This month')}
                        </span>
                        {aggregate.allPartial ? (
                            <span className="font-semibold">
                                {__('The first month is partial')}
                            </span>
                        ) : (
                            <span className="font-semibold tabular-nums">
                                <AmountDisplay
                                    amountInCents={aggregate.saved}
                                    currencyCode={currencyCode}
                                />{' '}
                                {__('of')}{' '}
                                <AmountDisplay
                                    amountInCents={aggregate.target}
                                    currencyCode={currencyCode}
                                />
                            </span>
                        )}
                    </div>
                    {lastMonth && (
                        <div className="flex flex-col gap-0.5">
                            <span className="text-muted-foreground capitalize">
                                {formatDate(
                                    monthDate(lastMonth.month),
                                    'MMMM',
                                    locale,
                                )}
                            </span>
                            <span className="font-semibold">
                                {__(':met of :total met', {
                                    met: lastMonth.met,
                                    total: lastMonth.total,
                                })}
                            </span>
                        </div>
                    )}
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {goals.map((goal) => (
                    <MonthlySavingsGoalCard
                        key={goal.id}
                        savingsGoal={goal}
                        currencyCode={currencyCode}
                    />
                ))}
            </div>

            <MonthStatusLegend
                statuses={legendStatuses(
                    ['met', 'missed', 'in_progress', 'none'],
                    goals.flatMap((goal) => goal.monthly?.history ?? []),
                )}
            />
        </section>
    );
}
