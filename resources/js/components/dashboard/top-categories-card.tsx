import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Category } from '@/types/category';
import * as Icons from 'lucide-react';
import { LucideIcon, TrendingDown, TrendingUp } from 'lucide-react';

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

export function TopCategoriesCard({
    categories,
    loading,
}: TopCategoriesCardProps) {
    const formatter = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    });

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
            <CardHeader className='gap-2'>
                <CardTitle>Top spending categories</CardTitle>
                <CardDescription>on the last 30 days</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-4">
                    {categories.map((item) => {
                        const Icon = (Icons[
                            item.category.icon as keyof typeof Icons
                        ] || Icons.HelpCircle) as LucideIcon;

                        const percentageChange =
                            item.previous_amount > 0
                                ? ((item.amount - item.previous_amount) /
                                    item.previous_amount) *
                                100
                                : null;
                        const isSpendingLess =
                            percentageChange !== null && percentageChange < 0;
                        const percentage =
                            item.total_amount > 0
                                ? (item.amount / item.total_amount) * 100
                                : 0;

                        const TrendIcon = isSpendingLess
                            ? TrendingDown
                            : TrendingUp;
                        const trendColorClass = isSpendingLess
                            ? 'text-green-600/70 dark:text-green-400/70'
                            : 'text-red-600/70 dark:text-red-400/70';

                        return (
                            <div key={item.category.id} className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <div className="flex items-center gap-2">
                                        <div
                                            className="flex h-8 w-4 ml-0.5 shrink-0 items-center justify-center rounded-full"
                                            style={{
                                                backgroundColor: `${item.category.color}20`,
                                                color: item.category.color,
                                            }}
                                        >
                                            <Icon className="size-4" />
                                        </div>
                                        <span className="text-sm font-medium">
                                            {item.category.name}
                                        </span>
                                    </div>
                                    <div className="ml-auto flex items-center gap-3">
                                        {percentageChange !== null && (
                                            <div className="flex items-center gap-1 text-xs">
                                                <span
                                                    className={
                                                        isSpendingLess
                                                            ? 'bg-green-100/25 dark:bg-green-900/25'
                                                            : ''
                                                    }
                                                >
                                                    {percentageChange >= 0
                                                        ? '+'
                                                        : ''}
                                                    {percentageChange.toFixed(
                                                        0,
                                                    )}
                                                    %
                                                </span>
                                                <TrendIcon
                                                    className={`h-4 w-4 ${trendColorClass}`}
                                                />
                                            </div>
                                        )}
                                        <span className="text-sm font-semibold">
                                            {formatter.format(
                                                item.amount / 100,
                                            )}
                                        </span>
                                    </div>
                                </div>
                                <Progress
                                    value={percentage}
                                    className="h-2"
                                    indicatorColor={item.category.color}
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
