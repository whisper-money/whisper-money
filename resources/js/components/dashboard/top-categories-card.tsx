import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import {
    Category,
    type CategoryColor,
    getCategoryColorClasses,
} from '@/types/category';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { format, subDays } from 'date-fns';
import * as Icons from 'lucide-react';
import { LucideIcon } from 'lucide-react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

interface CategoryData {
    category: Category | null;
    category_id?: string | null;
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
    const { auth } = usePage<SharedData>().props;
    const { categoryBarColor } = useChartColors();

    if (loading || !auth?.user) {
        return (
            <Card className="w-full">
                <CardHeader>
                    <CardTitle>{__('Top Spending Categories')}</CardTitle>
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

    const now = new Date();
    const dateFrom = format(subDays(now, 30), 'yyyy-MM-dd');
    const dateTo = format(now, 'yyyy-MM-dd');

    return (
        <Card className="w-full">
            <CardHeader className="gap-2">
                <CardTitle>{__('Top spending categories')}</CardTitle>
                <CardDescription>{__('on the last 30 days')}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-4">
                    {categories.map((item, index) => {
                        const category = item.category;
                        const categoryId =
                            category?.id ?? item.category_id ?? 'uncategorized';
                        const categoryName =
                            category?.name ?? __('Uncategorized');
                        const categoryIcon = category?.icon ?? 'HelpCircle';
                        const categoryColorName =
                            category?.color ?? ('gray' as CategoryColor);
                        const Icon = (Icons[
                            categoryIcon as keyof typeof Icons
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
                        const categoryColor =
                            getCategoryColorClasses(categoryColorName);
                        const chartColor = categoryBarColor(
                            categoryColorName,
                            index,
                        );

                        const categoryUrl = transactionsIndex({
                            query: {
                                category_ids: categoryId,
                                date_from: dateFrom,
                                date_to: dateTo,
                            },
                        }).url;

                        return (
                            <Link
                                key={categoryId}
                                href={categoryUrl}
                                className="group -mx-1.5 my-1.5 block space-y-2 rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                            >
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
                                        {categoryName}
                                    </span>
                                    {percentageChange !== null && (
                                        <PercentageTrendIndicator
                                            trend={percentageChange}
                                            label=""
                                            previousAmount={
                                                item.previous_amount
                                            }
                                            currentAmount={item.amount}
                                            currencyCode={
                                                auth.user.currency_code
                                            }
                                            invertColors
                                            className="shrink-0 text-xs"
                                        />
                                    )}
                                    <AmountDisplay
                                        amountInCents={item.amount}
                                        currencyCode={auth.user.currency_code}
                                        variant="compact"
                                        minimumFractionDigits={0}
                                        maximumFractionDigits={0}
                                        className="shrink-0"
                                    />
                                </div>
                                <Progress
                                    value={percentage}
                                    className="h-2"
                                    indicatorColor={chartColor}
                                />
                            </Link>
                        );
                    })}
                    {categories.length === 0 && (
                        <div className="py-8 text-center text-muted-foreground">
                            {__('No spending data this month')}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
