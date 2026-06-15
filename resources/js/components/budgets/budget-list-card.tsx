import { show } from '@/actions/App/Http/Controllers/BudgetController';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import { Budget, getBudgetPeriodTypeLabel } from '@/types/budget';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ArrowRight, Calendar } from 'lucide-react';
import { useMemo } from 'react';

interface Props {
    budget: Budget;
    currencyCode: string;
}

export function BudgetListCard({ budget, currencyCode }: Props) {
    const locale = useLocale();
    const currentPeriod = budget.periods?.[0];

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
        const percentageUsed =
            totalAllocated > 0 ? (totalSpent / totalAllocated) * 100 : 0;

        return {
            totalAllocated,
            totalSpent,
            remaining,
            percentageUsed,
        };
    }, [currentPeriod]);

    const periodLabel = useMemo(() => {
        if (!currentPeriod) return __('No active period');

        const start = formatDate(currentPeriod.start_date, 'MMM d', locale);
        const end = formatDate(currentPeriod.end_date, 'MMM d', locale);

        return `${start} - ${end}`;
    }, [currentPeriod, locale]);

    const statusColor = useMemo(() => {
        if (stats.percentageUsed >= 100)
            return 'text-red-600 dark:text-red-400';
        if (stats.percentageUsed >= 80)
            return 'text-yellow-600 dark:text-yellow-400';
        return 'text-green-600 dark:text-green-400';
    }, [stats.percentageUsed]);

    const trackingNames = useMemo(() => {
        return [
            ...(budget.categories?.map((category) => category.name) ?? []),
            ...(budget.labels?.map((label) => label.name) ?? []),
        ];
    }, [budget]);

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-xl">
                            <Link
                                href={show({ budget: budget.id }).url}
                                className="-my-1 -ml-1.5 inline-flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                            >
                                {budget.name}
                            </Link>
                        </CardTitle>
                        <CardDescription className="flex items-center gap-2">
                            <Calendar className="h-3 w-3" />
                            {periodLabel}
                        </CardDescription>
                    </div>
                    <Badge variant="outline">
                        {__(getBudgetPeriodTypeLabel(budget.period_type))}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
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

                <div className="flex items-center justify-between gap-2 border-t pt-4">
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
                    <Link href={show({ budget: budget.id }).url}>
                        <Button
                            className="cursor-pointer"
                            variant="ghost"
                            size="sm"
                        >
                            {__('View Details')}

                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Button>
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}
