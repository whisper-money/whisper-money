import { show } from '@/actions/App/Http/Controllers/BudgetController';
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
import { formatCurrency } from '@/utils/currency';
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

    const trackingLabel = useMemo(() => {
        if (budget.category) return budget.category.name;
        if (budget.label) return budget.label.name;
        return __('No tracking');
    }, [budget]);

    return (
        <Card className="transition-shadow hover:shadow-md">
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-xl">{budget.name}</CardTitle>
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
                            {formatCurrency(
                                stats.totalSpent,
                                currencyCode,
                                locale,
                            )}{' '}
                            {__('of')}{' '}
                            {formatCurrency(
                                stats.totalAllocated,
                                currencyCode,
                                locale,
                            )}
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
                            {formatCurrency(
                                stats.remaining,
                                currencyCode,
                                locale,
                            )}
                        </span>
                    </div>
                </div>

                <div className="flex items-center justify-between border-t pt-4">
                    <span className="text-sm text-muted-foreground">
                        {__('Tracking:')} {trackingLabel}
                    </span>
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
