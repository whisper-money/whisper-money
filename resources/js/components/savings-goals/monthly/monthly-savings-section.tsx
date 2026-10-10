import { AmountDisplay } from '@/components/ui/amount-display';
import { useLocale } from '@/hooks/use-locale';
import {
    aggregateMonthlyGoals,
    currentMonthOf,
    daysLeftLabel,
    monthDate,
} from '@/lib/monthly-savings';
import { SavingsGoal } from '@/types/savings-goal';
import { formatDate, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Repeat } from 'lucide-react';
import { MonthStatusLegend } from './month-status';
import { MonthlySavingsGoalCard } from './monthly-savings-goal-card';

interface Props {
    goals: SavingsGoal[];
    currencyCode: string;
}

/**
 * The Planning page's "Monthly savings" block: this month across every running
 * monthly goal, then one card per goal. It sits above the reorderable list on
 * purpose — a goal that starts over every month is not ranked against budgets.
 */
export function MonthlySavingsSection({ goals, currencyCode }: Props) {
    const locale = useLocale();
    const currentMonth = currentMonthOf(goals);
    const aggregate = aggregateMonthlyGoals(goals, currentMonth);

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
                    </div>
                    {aggregate.previousTotal > 0 && (
                        <div className="flex flex-col gap-0.5">
                            <span className="text-muted-foreground capitalize">
                                {formatDate(
                                    monthDate(aggregate.previousMonth),
                                    'MMMM',
                                    locale,
                                )}
                            </span>
                            <span className="font-semibold">
                                {__(':met of :total met', {
                                    met: aggregate.previousMet,
                                    total: aggregate.previousTotal,
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

            <MonthStatusLegend />
        </section>
    );
}
