import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { Category, getCategoryColorClasses } from '@/types/category';
import * as Icons from 'lucide-react';
import { LucideIcon } from 'lucide-react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

interface CategoryData {
    category: Category;
    amount: number;
    previous_amount: number;
    total_amount: number;
}

interface TopCategoriesCardProps {
    categories: CategoryData[];
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
    'var(--chart-7)',
    'var(--chart-8)',
    'var(--chart-8)',
];

export function TopCategoriesCard({
    categories,
    loading,
}: TopCategoriesCardProps) {
    if (loading) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>Top Spending Categories</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="space-y-4">
                        {Array.from({ length: 5 }).map((_, i) => (
                            <div key={i} className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <div className="size-8 animate-pulse rounded-full bg-gray-200 dark:bg-gray-700" />
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
        <Card className="col-span-3">
            <CardHeader className="gap-2">
                <CardTitle>Top spending categories</CardTitle>
                <CardDescription>on the last 30 days</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-4">
                    {categories.map((item, index) => {
                        const Icon = (Icons[
                            item.category.icon as keyof typeof Icons
                        ] || Icons.HelpCircle) as LucideIcon;

                        const percentageChange =
                            item.previous_amount > 0
                                ? ((item.amount - item.previous_amount) /
                                      item.previous_amount) *
                                  100
                                : null;
                        const percentage =
                            item.total_amount > 0
                                ? (item.amount / item.total_amount) * 100
                                : 0;
                        const categoryColor = getCategoryColorClasses(
                            item.category.color,
                        );
                        const chartColor =
                            CHART_COLORS[index % CHART_COLORS.length];

                        return (
                            <div key={item.category.id} className="space-y-2">
                                <div className="flex min-w-0 items-center gap-2">
                                    <div
                                        className={cn([
                                            'flex size-6 shrink-0 items-center justify-center rounded-full',
                                            `${categoryColor.bg} ${categoryColor.text}`,
                                        ])}
                                    >
                                        <Icon className="size-4" />
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
                                            invertColors
                                            className="shrink-0 text-xs"
                                        />
                                    )}
                                    <span className="shrink-0 text-sm font-semibold tabular-nums">
                                        <AmountDisplay
                                            amountInCents={item.amount}
                                            currencyCode="USD"
                                            minimumFractionDigits={0}
                                            maximumFractionDigits={0}
                                        />
                                    </span>
                                </div>
                                <Progress
                                    value={percentage}
                                    className="h-2"
                                    indicatorColor={chartColor}
                                />
                            </div>
                        );
                    })}
                    {categories.length === 0 && (
                        <div className="py-8 text-center text-muted-foreground">
                            No spending data this month
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
