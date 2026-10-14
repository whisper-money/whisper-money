import { show } from '@/actions/App/Http/Controllers/SavingsGoalController';
import { PlanningCard } from '@/components/shared/planning-card';
import { Badge } from '@/components/ui/badge';
import { monthKey } from '@/lib/monthly-savings';
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { Repeat } from 'lucide-react';
import { MonthlyBadge, noJudgedMonthYet } from './month-status';
import { MonthStrip } from './month-strip';
import {
    CurrentMonthProgress,
    MonthlyTargetRule,
    SignedAmount,
} from './monthly-goal-figures';

interface Props {
    savingsGoal: SavingsGoal;
    currencyCode: string;
}

/**
 * A monthly goal on the Planning page: this month's progress, the last twelve
 * months as a strip, and the running tally.
 */
export function MonthlySavingsGoalCard({ savingsGoal, currencyCode }: Props) {
    const monthly = savingsGoal.monthly;
    const archived = !!savingsGoal.archived_at;
    // The server's month, not the browser's: the strip has to line up with the
    // stats it draws.
    const endMonth = monthly?.history.at(-1)?.month ?? monthKey(new Date());

    return (
        <PlanningCard
            href={show({ savingsGoal: savingsGoal.id }).url}
            title={savingsGoal.name}
            dimmed={archived}
            badge={
                archived ? (
                    <Badge variant="secondary">{__('Archived')}</Badge>
                ) : (
                    <MonthlyBadge>{__('Monthly')}</MonthlyBadge>
                )
            }
            description={
                <>
                    <Repeat className="h-3 w-3" />
                    <MonthlyTargetRule
                        goal={savingsGoal}
                        currencyCode={currencyCode}
                    />
                </>
            }
        >
            {monthly?.current && !archived && (
                <CurrentMonthProgress
                    current={monthly.current}
                    currencyCode={currencyCode}
                />
            )}

            {monthly && (
                <>
                    <MonthStrip history={monthly.history} endMonth={endMonth} />
                    <div className="flex items-center justify-between gap-2 text-sm">
                        <span>
                            {monthly.months_closed === 0
                                ? noJudgedMonthYet(archived)
                                : __(':met of :total months met', {
                                      met: monthly.months_met,
                                      total: monthly.months_closed,
                                  })}
                        </span>
                        {monthly.months_closed > 0 && (
                            <span>
                                <SignedAmount
                                    amount={monthly.cumulative_difference}
                                    currencyCode={currencyCode}
                                />{' '}
                                <span className="text-muted-foreground">
                                    {__('overall')}
                                </span>
                            </span>
                        )}
                    </div>
                </>
            )}
        </PlanningCard>
    );
}
