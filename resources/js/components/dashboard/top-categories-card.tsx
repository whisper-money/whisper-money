import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { CategoryAnalysisButton } from '@/components/categories/category-analysis-button';
import {
    CategoryBreakdownRow,
    type CategoryBreakdownAdapter,
} from '@/components/shared/category-breakdown-list';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useExpandableCategories } from '@/hooks/use-expandable-categories';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import {
    Category,
    getCategoryColorClasses,
    type CategoryColor,
} from '@/types/category';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { format, subDays } from 'date-fns';
import * as Icons from 'lucide-react';
import { LucideIcon } from 'lucide-react';
import { useCallback, useMemo } from 'react';

interface CategoryData {
    category: Category | null;
    category_id?: string | null;
    amount: number;
    previous_amount: number;
    total_amount: number;
    has_children?: boolean;
    is_direct?: boolean;
}

interface TopCategoriesCardProps {
    categories: CategoryData[];
    loading?: boolean;
}

function rowKey(item: CategoryData): string {
    return `${item.category?.id ?? item.category_id ?? 'uncategorized'}:${item.is_direct ? 'direct' : 'node'}`;
}

export function TopCategoriesCard({
    categories,
    loading,
}: TopCategoriesCardProps) {
    const { auth } = usePage<SharedData>().props;
    const { categoryBarColor } = useChartColors();

    const { dateFrom, dateTo } = useMemo(() => {
        const now = new Date();
        return {
            dateFrom: format(subDays(now, 30), 'yyyy-MM-dd'),
            dateTo: format(now, 'yyyy-MM-dd'),
        };
    }, []);

    const fetchChildren = useCallback(
        async (categoryId: string): Promise<CategoryData[]> => {
            const params = new URLSearchParams({
                from: dateFrom,
                to: dateTo,
                parent: categoryId,
            });
            const response = await fetch(
                `/api/dashboard/top-categories?${params.toString()}`,
            );
            return response.json();
        },
        [dateFrom, dateTo],
    );

    const expandable = useExpandableCategories<CategoryData>(
        fetchChildren,
        dateFrom,
    );

    const adapter: CategoryBreakdownAdapter<CategoryData> = {
        getId: (item) =>
            item.category?.id ?? item.category_id ?? 'uncategorized',
        getKey: (item) => rowKey(item),
        getName: (item) => item.category?.name ?? __('Uncategorized'),
        getAmount: (item) => item.amount,
        getPercentage: (item) =>
            item.total_amount > 0 ? (item.amount / item.total_amount) * 100 : 0,
        getBarColor: (item, index) =>
            categoryBarColor(
                (item.category?.color ?? 'gray') as CategoryColor,
                index,
            ),
        renderLeading: (item) => {
            const color = getCategoryColorClasses(
                (item.category?.color ?? 'gray') as CategoryColor,
            );
            const Icon = (Icons[
                (item.category?.icon ?? 'HelpCircle') as keyof typeof Icons
            ] || Icons.HelpCircle) as LucideIcon;

            return (
                <div
                    className={cn([
                        'flex size-6 shrink-0 items-center justify-center rounded-full',
                        `${color.bg} ${color.text}`,
                    ])}
                >
                    <Icon className="size-4" />
                </div>
            );
        },
        getHref: (item) =>
            transactionsIndex({
                query: {
                    category_ids:
                        item.category?.id ??
                        item.category_id ??
                        'uncategorized',
                    date_from: dateFrom,
                    date_to: dateTo,
                },
            }).url,
        getTrend: (item) =>
            item.previous_amount > 0
                ? {
                      change:
                          ((item.amount - item.previous_amount) /
                              item.previous_amount) *
                          100,
                      previousAmount: item.previous_amount,
                      currentAmount: item.amount,
                  }
                : null,
        canExpand: (item) =>
            Boolean(item.has_children && !item.is_direct && item.category),
    };

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

    return (
        <Card className="w-full">
            <CardHeader className="gap-2">
                <div className="flex items-start justify-between gap-2">
                    <CardTitle>{__('Top spending categories')}</CardTitle>
                    <CategoryAnalysisButton
                        widgetKey="dashboard-top-categories"
                        firstCategoryId={
                            categories[0]?.category?.id ??
                            categories[0]?.category_id ??
                            null
                        }
                    />
                </div>
                <CardDescription>{__('on the last 30 days')}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {categories.map((item, index) => (
                        <CategoryBreakdownRow
                            key={rowKey(item)}
                            item={item}
                            index={index}
                            currencyCode={auth.user.currency_code}
                            adapter={adapter}
                            expandable={expandable}
                            expandColumn
                        />
                    ))}
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
