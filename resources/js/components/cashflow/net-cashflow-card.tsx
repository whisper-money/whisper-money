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

interface NetCashflowCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
    currency?: string;
}

interface PeriodComparisonProps {
    diff: number;
    isFavorable: boolean;
    currency: string;
}

function PeriodComparison({
    diff,
    isFavorable,
    currency,
}: PeriodComparisonProps) {
    const isIncrease = diff >= 0;
    const Icon = isIncrease ? TrendingUp : TrendingDown;

    return (
        <div className="mt-1 flex items-center gap-1 text-xs">
            <Icon
                className={cn(
                    'size-3',
                    isFavorable
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-red-600 dark:text-red-400',
                )}
            />
            <span>
                {isIncrease ? '+' : ''}
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

export function NetCashflowCard({
    current,
    previous,
    loading,
    currency = 'USD',
}: NetCashflowCardProps) {
    if (loading) {
        return (
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        {__('Net Cashflow')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-12 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const diff = current.net - previous.net;
    const rateDiff = current.savings_rate - previous.savings_rate;
    const rateDiffIsPositive = rateDiff >= 0;
    const diffIsPositive = diff >= 0;
    const incomeDiff = current.income - previous.income;
    const expenseDiff = current.expense - previous.expense;
    const hasPreviousData = previous.income > 0 || previous.expense > 0;

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    {__('Net Cashflow')}
                </CardTitle>
                <CardDescription>{__('Income minus expenses')}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <div className={cn('flex items-center gap-1')}>
                        <AmountDisplay
                            amountInCents={current.net}
                            currencyCode={currency}
                            size="4xl"
                            weight="bold"
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            className={
                                current.net >= 0
                                    ? 'text-green-600 dark:text-green-400'
                                    : 'text-red-600 dark:text-red-400'
                            }
                            highlightPositive
                        />
                    </div>
                    <span className="text-lg font-medium text-muted-foreground tabular-nums">
                        {current.savings_rate.toFixed(1)}%
                    </span>
                </div>
                {hasPreviousData && (
                    <>
                        <div className="mt-2 flex items-center gap-1 text-sm">
                            {rateDiffIsPositive ? (
                                <TrendingUp className="size-4 text-green-600 dark:text-green-400" />
                            ) : (
                                <TrendingDown className="size-4 text-red-600 dark:text-red-400" />
                            )}
                            <span>
                                {rateDiffIsPositive ? '+' : ''}
                                {rateDiff.toFixed(1)}%
                            </span>
                            <span className="text-muted-foreground">
                                {__('vs last period')}
                            </span>
                        </div>
                        <div className="mt-1 flex items-center gap-1 text-xs">
                            {diffIsPositive ? (
                                <TrendingUp className="size-3 text-green-600 dark:text-green-400" />
                            ) : (
                                <TrendingDown className="size-3 text-red-600 dark:text-red-400" />
                            )}
                            <span>
                                {diffIsPositive ? '+' : ''}
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
                    </>
                )}
                <div className="mt-3 grid grid-cols-2 gap-4 border-t pt-3">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Income')}
                        </p>
                        <AmountDisplay
                            amountInCents={current.income}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                            highlightPositive
                        />
                        {hasPreviousData && (
                            <PeriodComparison
                                diff={incomeDiff}
                                isFavorable={incomeDiff >= 0}
                                currency={currency}
                            />
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Expenses')}
                        </p>
                        <AmountDisplay
                            amountInCents={current.expense}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                        />
                        {hasPreviousData && (
                            <PeriodComparison
                                diff={expenseDiff}
                                isFavorable={expenseDiff <= 0}
                                currency={currency}
                            />
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
