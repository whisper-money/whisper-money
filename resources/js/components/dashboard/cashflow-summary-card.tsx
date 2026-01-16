import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { cashflow } from '@/routes';
import { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { endOfMonth, format, startOfMonth } from 'date-fns';
import { ArrowRight, TrendingDown, TrendingUp } from 'lucide-react';
import { useEffect, useState } from 'react';

interface CashflowSummary {
    income: number;
    expense: number;
    net: number;
    savings_rate: number;
}

interface CashflowSummaryCardProps {
    loading?: boolean;
}

export function CashflowSummaryCard({ loading }: CashflowSummaryCardProps) {
    const { auth } = usePage<SharedData>().props;

    const [data, setData] = useState<{
        current: CashflowSummary;
        previous: CashflowSummary;
    } | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const now = new Date();
                const from = format(startOfMonth(now), 'yyyy-MM-dd');
                const to = format(endOfMonth(now), 'yyyy-MM-dd');
                const params = new URLSearchParams({ from, to });

                const response = await fetch(
                    `/api/cashflow/summary?${params.toString()}`,
                );
                const result = await response.json();
                setData(result);
            } catch (error) {
                console.error('Failed to fetch cashflow summary:', error);
            } finally {
                setIsLoading(false);
            }
        };

        fetchData();
    }, []);

    if (!auth?.user || isLoading || loading) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>Cashflow</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-3 gap-4">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <div key={i} className="space-y-2">
                                <div className="h-3 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                                <div className="h-6 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>
        );
    }

    if (!data) {
        return null;
    }

    const { current } = data;
    const isPositiveNet = current.net >= 0;

    return (
        <Card className="col-span-3">
            <CardHeader className="gap-1">
                <div className="flex items-center justify-between">
                    <CardTitle>Cashflow</CardTitle>
                    <Link
                        href={cashflow().url}
                        className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        View details
                        <ArrowRight className="size-4" />
                    </Link>
                </div>
                <CardDescription>
                    This month's income and expenses
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-3 gap-4">
                    {/* Income */}
                    <div>
                        <p className="text-xs text-muted-foreground">Income</p>
                        <AmountDisplay
                            amountInCents={current.income}
                            currencyCode={auth.user.currency_code}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="semibold"
                            highlightPositive
                        />
                    </div>

                    {/* Expenses */}
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Expenses
                        </p>
                        <AmountDisplay
                            amountInCents={current.expense}
                            currencyCode={auth.user.currency_code}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="semibold"
                        />
                    </div>

                    {/* Net */}
                    <div>
                        <p className="text-xs text-muted-foreground">Net</p>
                        <div className="flex items-center gap-1">
                            {isPositiveNet ? (
                                <TrendingUp className="size-4 text-green-600 dark:text-green-400" />
                            ) : (
                                <TrendingDown className="size-4 text-red-600 dark:text-red-400" />
                            )}
                            <AmountDisplay
                                amountInCents={Math.abs(current.net)}
                                currencyCode={auth.user.currency_code}
                                minimumFractionDigits={0}
                                maximumFractionDigits={0}
                                weight="semibold"
                                highlightPositive
                            />
                        </div>
                    </div>
                </div>

                {/* Savings rate footer */}
                <div className="mt-4 flex items-center justify-between border-t pt-3">
                    <span className="text-xs text-muted-foreground">
                        Savings rate
                    </span>
                    <span
                        className={cn(
                            'text-sm font-medium',
                            current.savings_rate >= 20
                                ? 'text-green-600 dark:text-green-400'
                                : current.savings_rate >= 0
                                  ? 'text-yellow-600 dark:text-yellow-400'
                                  : 'text-red-600 dark:text-red-400',
                        )}
                    >
                        {current.savings_rate.toFixed(1)}%
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}
