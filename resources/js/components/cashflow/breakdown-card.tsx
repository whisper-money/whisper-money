import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { BreakdownData } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { getCategoryColorClasses } from '@/types/category';
import * as Icons from 'lucide-react';
import { LucideIcon } from 'lucide-react';

interface BreakdownCardProps {
    type: 'income' | 'expense';
    data: BreakdownData;
    loading?: boolean;
}

const CHART_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
    'var(--chart-7)',
    'var(--chart-8)',
];

export function BreakdownCard({ type, data, loading }: BreakdownCardProps) {
    const title = type === 'income' ? 'Income Sources' : 'Expense Categories';
    const description =
        type === 'income'
            ? 'Where your money comes from'
            : 'Where your money goes';
    const emptyMessage =
        type === 'income' ? 'No income this period' : 'No expenses this period';

    if (loading) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">{title}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="space-y-4">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <div key={i} className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <div className="size-6 animate-pulse rounded-full bg-gray-200 dark:bg-gray-700" />
                                    <div className="h-4 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                                    <div className="ml-auto h-4 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                                </div>
                                <div className="h-2 w-full animate-pulse rounded-full bg-gray-200 dark:bg-gray-700" />
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader className="gap-1 pb-4">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-base">{title}</CardTitle>
                    <AmountDisplay
                        amountInCents={data.total}
                        currencyCode="USD"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        weight="semibold"
                        highlightPositive
                    />
                </div>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {data.data.map((item, index) => {
                        const Icon = (Icons[
                            item.category.icon as keyof typeof Icons
                        ] || Icons.HelpCircle) as LucideIcon;

                        const percentageChange =
                            item.previous_amount > 0
                                ? ((item.amount - item.previous_amount) /
                                      item.previous_amount) *
                                  100
                                : null;

                        const categoryColor = getCategoryColorClasses(
                            item.category.color,
                        );
                        const chartColor =
                            CHART_COLORS[index % CHART_COLORS.length];

                        return (
                            <div key={item.category_id} className="space-y-1.5">
                                <div className="flex min-w-0 items-center gap-2">
                                    <div
                                        className={cn([
                                            'flex size-6 shrink-0 items-center justify-center rounded-full',
                                            `${categoryColor.bg} ${categoryColor.text}`,
                                        ])}
                                    >
                                        <Icon className="size-3.5" />
                                    </div>
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                        {item.category.name}
                                    </span>
                                    {percentageChange !== null && (
                                        <PercentageTrendIndicator
                                            trend={percentageChange}
                                            label=""
                                            previousAmount={
                                                item.previous_amount
                                            }
                                            currentAmount={item.amount}
                                            currencyCode="USD"
                                            invertColors={type === 'expense'}
                                            className="shrink-0 text-xs"
                                        />
                                    )}
                                    <div className="flex shrink-0 items-center gap-2">
                                        <span className="text-xs text-muted-foreground">
                                            {item.percentage.toFixed(0)}%
                                        </span>
                                        <AmountDisplay
                                            amountInCents={item.amount}
                                            currencyCode="USD"
                                            variant="compact"
                                            minimumFractionDigits={0}
                                            maximumFractionDigits={0}
                                        />
                                    </div>
                                </div>
                                <Progress
                                    value={item.percentage}
                                    className="h-1.5"
                                    indicatorColor={chartColor}
                                />
                            </div>
                        );
                    })}
                    {data.data.length === 0 && (
                        <div className="py-6 text-center text-sm text-muted-foreground">
                            {emptyMessage}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
