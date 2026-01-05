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
import { ArrowDown, ArrowUp, TrendingDown, TrendingUp } from 'lucide-react';

interface NetCashflowCardProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    loading?: boolean;
}

export function NetCashflowCard({
    current,
    previous,
    loading,
}: NetCashflowCardProps) {
    if (loading) {
        return (
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        Net Cashflow
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-12 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    const isPositive = current.net >= 0;
    const diff = current.net - previous.net;
    const diffIsPositive = diff >= 0;
    const hasPreviousData = previous.income > 0 || previous.expense > 0;

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    Net Cashflow
                </CardTitle>
                <CardDescription>Income minus expenses</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="flex items-baseline gap-2">
                    <div className={cn('flex items-center gap-1')}>
                        {isPositive ? (
                            <ArrowUp
                                className={cn(
                                    'size-4',
                                    'text-green-600 dark:text-green-400',
                                )}
                            />
                        ) : (
                            <ArrowDown
                                className={cn(
                                    'size-4',
                                    'text-red-600 dark:text-red-400',
                                )}
                            />
                        )}
                        <AmountDisplay
                            amountInCents={Math.abs(current.net)}
                            currencyCode="USD"
                            size="2xl"
                            weight="bold"
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            highlightPositive
                        />
                    </div>
                </div>
                {hasPreviousData && (
                    <div className={cn('mt-2 flex items-center gap-1 text-sm')}>
                        {diffIsPositive ? (
                            <TrendingUp
                                className={cn(
                                    'size-4',
                                    'text-green-600 dark:text-green-400',
                                )}
                            />
                        ) : (
                            <TrendingDown
                                className={cn(
                                    'size-4',
                                    'text-red-600 dark:text-red-400',
                                )}
                            />
                        )}
                        <span>
                            {diffIsPositive ? '+' : ''}
                            <AmountDisplay
                                amountInCents={diff}
                                currencyCode="USD"
                                minimumFractionDigits={0}
                                maximumFractionDigits={0}
                                className="text-sm"
                                highlightPositive
                            />
                        </span>
                        <span className="text-muted-foreground">
                            vs last period
                        </span>
                    </div>
                )}
                <div className="mt-3 grid grid-cols-2 gap-4 border-t pt-3">
                    <div>
                        <p className="text-xs text-muted-foreground">Income</p>
                        <AmountDisplay
                            amountInCents={current.income}
                            currencyCode="USD"
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                            highlightPositive
                        />
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Expenses
                        </p>
                        <AmountDisplay
                            amountInCents={current.expense}
                            currencyCode="USD"
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="medium"
                        />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
