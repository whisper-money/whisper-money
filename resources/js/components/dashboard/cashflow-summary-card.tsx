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
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, TrendingDown, TrendingUp } from 'lucide-react';

interface CashflowSummary {
    income: number;
    expense: number;
    net: number;
    savings_rate: number;
}

interface CashflowSummaryCardProps {
    data?: {
        current: CashflowSummary;
        previous: CashflowSummary;
    } | null;
    loading?: boolean;
}

export function CashflowSummaryCard({
    data,
    loading,
}: CashflowSummaryCardProps) {
    const { auth } = usePage<SharedData>().props;

    if (!auth?.user || loading || !data) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>{__('Cashflow')}</CardTitle>
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

    const { current } = data;
    const isPositiveNet = current.net >= 0;

    return (
        <Card className="col-span-3">
            <CardHeader className="gap-1">
                <div className="flex items-center justify-between">
                    <CardTitle>{__('Cashflow')}</CardTitle>
                    <Link
                        href={cashflow().url}
                        className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        {__('View details')}

                        <ArrowRight className="size-4" />
                    </Link>
                </div>
                <CardDescription>
                    {__("This month's income and expenses")}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-3 gap-4">
                    {/* Income */}
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {__('Income')}
                        </p>
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
                            {__('Expenses')}
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
                        <p className="text-xs text-muted-foreground">
                            {__('Net')}
                        </p>
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
                        {__('Savings rate')}
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
