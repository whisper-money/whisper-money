import { index as planningIndex } from '@/actions/App/Http/Controllers/BudgetController';
import { show } from '@/actions/App/Http/Controllers/SavingsGoalController';
import {
    MonthlyGoalProgress,
    SavedOfTarget,
} from '@/components/savings-goals/monthly/monthly-goal-figures';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import {
    aggregateMonthlyGoals,
    currentMonthOf,
    daysLeftLabel,
    monthDate,
} from '@/lib/monthly-savings';
import { SavingsGoal } from '@/types/savings-goal';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Repeat } from 'lucide-react';

interface Props {
    goals: SavingsGoal[];
    currencyCode: string;
}

/**
 * This month across every running monthly goal, then one line per goal. No
 * monthly goal, no card: the dashboard slot is only spent on it when there is
 * something to follow.
 */
export function MonthlySavingsDashboardCard({
    goals: allGoals,
    currencyCode,
}: Props) {
    const locale = useLocale();
    // A goal with no period for this month yet (the daily run has not opened
    // it) has nothing to show: drawn, it would read as a full bar of €0 of €0.
    const goals = allGoals.filter((goal) => goal.monthly?.current);

    if (goals.length === 0) {
        return null;
    }

    const currentMonth = currentMonthOf(goals);
    const aggregate = aggregateMonthlyGoals(goals, currentMonth);
    const monthName = (key: string) =>
        formatDate(monthDate(key), 'MMMM', locale);

    return (
        <Card>
            <CardHeader className="flex flex-row items-baseline justify-between gap-2">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Repeat className="size-4" />
                    {__('Monthly savings · :month', {
                        month: monthName(currentMonth),
                    })}
                </CardTitle>
                <Link
                    href={planningIndex().url}
                    className="text-sm text-muted-foreground hover:text-foreground"
                >
                    {__('See all')}
                </Link>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <SavedOfTarget
                        saved={aggregate.saved}
                        target={aggregate.target}
                        currencyCode={currencyCode}
                    />
                    <MonthlyGoalProgress
                        saved={aggregate.saved}
                        target={aggregate.target}
                    />
                    <span className="text-sm text-muted-foreground">
                        {[
                            aggregate.daysLeft !== null &&
                                daysLeftLabel(aggregate.daysLeft),
                            aggregate.previousTotal > 0 &&
                                __(':month: :met of :total met', {
                                    month: monthName(aggregate.previousMonth),
                                    met: aggregate.previousMet,
                                    total: aggregate.previousTotal,
                                }),
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </div>
                <ul className="flex flex-col gap-2.5 text-sm">
                    {goals.map((goal) => (
                        <GoalLine
                            key={goal.id}
                            goal={goal}
                            currencyCode={currencyCode}
                        />
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function GoalLine({
    goal,
    currencyCode,
}: {
    goal: SavingsGoal;
    currencyCode: string;
}) {
    const saved = goal.monthly?.current?.saved ?? 0;
    const target = goal.monthly?.current?.target ?? 0;

    return (
        <li className="grid grid-cols-[minmax(0,1fr)_5rem_auto] items-center gap-3">
            <Link
                href={show({ savingsGoal: goal.id }).url}
                className="truncate hover:underline"
            >
                {goal.name}
            </Link>
            <MonthlyGoalProgress
                saved={saved}
                target={target}
                className="h-1.5"
            />
            <span className="text-right text-muted-foreground tabular-nums">
                <AmountDisplay
                    amountInCents={saved}
                    currencyCode={currencyCode}
                    minimumFractionDigits={0}
                    maximumFractionDigits={0}
                />
                {' / '}
                <AmountDisplay
                    amountInCents={target}
                    currencyCode={currencyCode}
                    minimumFractionDigits={0}
                    maximumFractionDigits={0}
                />
            </span>
        </li>
    );
}
