import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { TrendingDown, TrendingUp } from 'lucide-react';

interface SavingsRateCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
}

interface PercentageComparisonProps {
    diff: number;
}

function PercentageComparison({ diff }: PercentageComparisonProps) {
    const isPositive = diff >= 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;

    return (
        <div className="mt-2 flex items-center gap-1 text-sm">
            <Icon
                className={cn(
                    'size-4',
                    isPositive
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-red-600 dark:text-red-400',
                )}
            />
            <span>
                {isPositive ? '+' : ''}
                {diff.toFixed(1)}%
            </span>
            <span className="text-muted-foreground">
                {__('vs last period')}
            </span>
        </div>
    );
}

interface AmountComparisonProps {
    diff: number;
    currency: string;
}

function AmountComparison({ diff, currency }: AmountComparisonProps) {
    const isPositive = diff >= 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;

    return (
        <div className="mt-1 flex items-center gap-1 text-xs">
            <Icon
                className={cn(
                    'size-3',
                    isPositive
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-red-600 dark:text-red-400',
                )}
            />
            <span>
                {isPositive ? '+' : ''}
                <AmountDisplay
                    amountInCents={diff}
                    currencyCode={currency}
                    minimumFractionDigits={0}
                    maximumFractionDigits={0}
                    className="text-xs"
                    highlightPositive
                />
            </span>
            <span className="text-muted-foreground">
                {__('vs last period')}
            </span>
        </div>
    );
}

export function SavingsRateCard({
    current,
    previous,
    loading,
    currency = 'USD',
}: SavingsRateCardProps) {
    if (loading) {
        return (
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        {__('Savings Rate')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-12 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const diff = current.savings_rate - previous.savings_rate;
    const savingsDiff = current.savings - previous.savings;
    const investmentsDiff = current.investments - previous.investments;
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
                    {__('Savings Rate')}
                </CardTitle>
                <CardDescription>
                    {__('Percentage of income saved')}
                </CardDescription>
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
                </div>
                {hasPreviousData && <PercentageComparison diff={diff} />}
                <div className="mt-3 grid grid-cols-2 gap-4 border-t pt-3">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Saved')}
                        </p>
                        <AmountDisplay
                            amountInCents={current.savings}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                            highlightPositive
                        />
                        {hasPreviousData && (
                            <AmountComparison
                                diff={savingsDiff}
                                currency={currency}
                            />
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Invested')}
                        </p>
                        <AmountDisplay
                            amountInCents={current.investments}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                            highlightPositive
                        />
                        {hasPreviousData && (
                            <AmountComparison
                                diff={investmentsDiff}
                                currency={currency}
                            />
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
