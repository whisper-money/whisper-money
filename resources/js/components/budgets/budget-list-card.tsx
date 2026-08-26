import { show } from '@/actions/App/Http/Controllers/BudgetController';
import { PlanningCard } from '@/components/shared/planning-card';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import {
    Budget,
    budgetPercentageUsed,
    budgetSeverity,
    getBudgetPeriodTypeLabel,
    getBudgetSeverityColor,
} from '@/types/budget';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Archive, Calendar } from 'lucide-react';
import { useMemo } from 'react';

interface Props {
    budget: Budget;
    currencyCode: string;
}

export function BudgetListCard({ budget, currencyCode }: Props) {
    const locale = useLocale();
    const currentPeriod = budget.periods?.[0];
    const archivedAt = budget.archived_at;

    const stats = useMemo(() => {
        if (!currentPeriod) {
            return {
                totalAllocated: 0,
                totalSpent: 0,
                remaining: 0,
                percentageUsed: 0,
            };
        }

        const totalAllocated = currentPeriod.allocated_amount;
        const totalSpent =
            currentPeriod.budget_transactions?.reduce(
                (sum, t) => sum + t.amount,
                0,
            ) ?? 0;

        const remaining = totalAllocated - totalSpent;

        return {
            totalAllocated,
            totalSpent,
            remaining,
            percentageUsed: budgetPercentageUsed(budget),
        };
    }, [budget, currentPeriod]);

    const periodLabel = useMemo(() => {
        if (archivedAt) {
            return __('Archived on :date', {
                date: formatDate(archivedAt, 'MMM d, yyyy', locale),
            });
        }

        if (!currentPeriod) return __('No active period');

        const start = formatDate(currentPeriod.start_date, 'MMM d', locale);
        const end = formatDate(currentPeriod.end_date, 'MMM d', locale);

        return `${start} - ${end}`;
    }, [archivedAt, currentPeriod, locale]);

    const statusColor = useMemo(
        () => getBudgetSeverityColor(budgetSeverity(budget)),
        [budget],
    );

    const trackingNames = useMemo(() => {
        return [
            ...(budget.categories?.map((category) => category.name) ?? []),
            ...(budget.labels?.map((label) => label.name) ?? []),
        ];
    }, [budget]);

    return (
        <PlanningCard
            href={show({ budget: budget.id }).url}
            title={budget.name}
            dimmed={!!archivedAt}
            badge={
                archivedAt ? (
                    <Badge variant="secondary">{__('Archived')}</Badge>
                ) : (
                    <Badge variant="outline">
                        {__(getBudgetPeriodTypeLabel(budget.period_type))}
                    </Badge>
                )
            }
            description={
                <>
                    {archivedAt ? (
                        <Archive className="h-3 w-3" />
                    ) : (
                        <Calendar className="h-3 w-3" />
                    )}
                    {periodLabel}
                </>
            }
            footerStart={
                <div className="flex flex-wrap items-center gap-1">
                    <span className="text-sm text-muted-foreground">
                        {__('Tracking:')}
                    </span>
                    {budget.is_catch_all ? (
                        <Badge variant="secondary">
                            {__('All untracked expenses')}
                        </Badge>
                    ) : trackingNames.length > 0 ? (
                        <>
                            {trackingNames.slice(0, 2).map((name) => (
                                <Badge key={name} variant="secondary">
                                    {name}
                                </Badge>
                            ))}
                            {trackingNames.length > 2 && (
                                <Badge variant="secondary">
                                    {__('+:count', {
                                        count: trackingNames.length - 2,
                                    })}
                                </Badge>
                            )}
                        </>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            {__('No tracking')}
                        </span>
                    )}
                </div>
            }
        >
            {/* A budget spends down, so it reads as a bar draining left to
                right. The savings goal card fills a ring instead. An archived
                budget has no period in progress, so it gets no bar at all
                rather than a row of zeros — its figures are on its own page. */}
            {!archivedAt && (
                <div className="space-y-2">
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {__('Spent')}
                        </span>
                        <span className={statusColor}>
                            <AmountDisplay
                                amountInCents={stats.totalSpent}
                                currencyCode={currencyCode}
                            />{' '}
                            {__('of')}{' '}
                            <AmountDisplay
                                amountInCents={stats.totalAllocated}
                                currencyCode={currencyCode}
                            />
                        </span>
                    </div>
                    <Progress
                        value={Math.min(stats.percentageUsed, 100)}
                        className="h-2"
                    />

                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {__('Remaining')}
                        </span>
                        <span className={statusColor}>
                            <AmountDisplay
                                amountInCents={stats.remaining}
                                currencyCode={currencyCode}
                            />
                        </span>
                    </div>
                </div>
            )}
        </PlanningCard>
    );
}
