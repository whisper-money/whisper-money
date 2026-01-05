import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { TrendingDown, TrendingUp } from 'lucide-react';

interface SavingsRateCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
}

export function SavingsRateCard({
    current,
    previous,
    loading,
}: SavingsRateCardProps) {
    if (loading) {
        return (
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        Savings Rate
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-12 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const diff = current.savings_rate - previous.savings_rate;
    const isPositive = diff >= 0;
    const hasPreviousData = previous.income > 0;

    // Determine color based on savings rate
    const rateColor =
        current.savings_rate >= 20
            ? 'text-green-600 dark:text-green-400'
            : current.savings_rate >= 10
              ? 'text-yellow-600 dark:text-yellow-400'
              : current.savings_rate >= 0
                ? 'text-orange-600 dark:text-orange-400'
                : 'text-red-600 dark:text-red-400';

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    Savings Rate
                </CardTitle>
                <CardDescription>Percentage of income saved</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex items-baseline gap-2">
                    <span
                        className={cn(
                            'text-3xl font-bold tabular-nums',
                            rateColor,
                        )}
                    >
                        {current.savings_rate.toFixed(1)}%
                    </span>
                    {hasPreviousData && (
                        <div className={cn('flex items-center gap-1 text-sm')}>
                            {isPositive ? (
                                <TrendingUp className="size-4 text-green-600 dark:text-green-400" />
                            ) : (
                                <TrendingDown className="size-4 text-red-600 dark:text-red-400" />
                            )}
                            <span>
                                {isPositive ? '+' : ''}
                                {diff.toFixed(1)}%
                            </span>
                        </div>
                    )}
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                    {current.savings_rate >= 20
                        ? "Great job! You're saving well."
                        : current.savings_rate >= 10
                          ? 'Good progress on your savings.'
                          : current.savings_rate >= 0
                            ? 'Consider saving more if possible.'
                            : 'Spending exceeds income this period.'}
                </p>
            </CardContent>
        </Card>
    );
}
