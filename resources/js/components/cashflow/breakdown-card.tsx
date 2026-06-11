import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { CategoryAnalysisButton } from '@/components/categories/category-analysis-button';
import {
    CategoryBreakdownRow,
    type CategoryBreakdownAdapter,
} from '@/components/shared/category-breakdown-list';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { BreakdownData, BreakdownItem } from '@/hooks/use-cashflow-data';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useExpandableCategories } from '@/hooks/use-expandable-categories';
import { cn } from '@/lib/utils';
import {
    getCategoryColorClasses,
    type CategoryColor,
    type CategoryIcon,
} from '@/types/category';
import { __ } from '@/utils/i18n';
import { format } from 'date-fns';
import * as Icons from 'lucide-react';
import { LucideIcon } from 'lucide-react';
import { useCallback } from 'react';

interface BreakdownCardProps {
    type: 'income' | 'expense';
    data: BreakdownData;
    loading?: boolean;
    currency?: string;
    period?: { from: Date; to: Date };
}

const fallbackCategory = {
    name: __('Uncategorized'),
    icon: 'HelpCircle' as CategoryIcon,
    color: 'gray' as CategoryColor,
};

function rowKey(item: BreakdownItem): string {
    return `${item.category_id ?? 'uncategorized'}:${item.is_direct ? 'direct' : 'node'}`;
}

export function BreakdownCard({
    type,
    data,
    loading,
    currency = 'USD',
    period,
}: BreakdownCardProps) {
    const { categoryBarColor } = useChartColors();

    const title =
        type === 'income' ? __('Income Sources') : __('Expense Categories');
    const description =
        type === 'income'
            ? __('Where your money comes from')
            : __('Where your money goes');
    const emptyMessage =
        type === 'income'
            ? __('No income this period')
            : __('No expenses this period');

    const periodKey = period
        ? `${format(period.from, 'yyyy-MM-dd')}:${format(period.to, 'yyyy-MM-dd')}`
        : null;

    const fetchChildren = useCallback(
        async (categoryId: string): Promise<BreakdownItem[]> => {
            if (!period) {
                return [];
            }

            const params = new URLSearchParams({
                from: format(period.from, 'yyyy-MM-dd'),
                to: format(period.to, 'yyyy-MM-dd'),
                type,
                parent: categoryId,
            });
            const response = await fetch(
                `/api/cashflow/breakdown?${params.toString()}`,
            );
            const json: BreakdownData = await response.json();
            return json.data;
        },
        [period, type],
    );

    const expandable = useExpandableCategories<BreakdownItem>(
        fetchChildren,
        periodKey,
    );

    const adapter: CategoryBreakdownAdapter<BreakdownItem> = {
        getId: (item) => item.category_id ?? '',
        getKey: (item) => rowKey(item),
        getName: (item) => (item.category ?? fallbackCategory).name,
        getAmount: (item) => item.amount,
        getPercentage: (item) => item.percentage,
        getBarColor: (item, index) =>
            categoryBarColor((item.category ?? fallbackCategory).color, index),
        renderLeading: (item) => {
            const category = item.category ?? fallbackCategory;
            const color = getCategoryColorClasses(category.color);
            const Icon = (Icons[category.icon as keyof typeof Icons] ||
                Icons.HelpCircle) as LucideIcon;

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
            period && item.category_id
                ? transactionsIndex({
                      query: {
                          category_ids: item.category_id,
                          date_from: format(period.from, 'yyyy-MM-dd'),
                          date_to: format(period.to, 'yyyy-MM-dd'),
                      },
                  }).url
                : null,
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
            Boolean(
                item.has_children &&
                !item.is_direct &&
                item.category_id &&
                period,
            ),
    };

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
                <div className="flex items-start justify-between gap-2">
                    <div className="flex flex-col gap-1">
                        <CardTitle className="text-base">{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                        <AmountDisplay
                            amountInCents={data.total}
                            currencyCode={currency}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                            weight="semibold"
                            highlightPositive
                            className="text-lg"
                        />
                    </div>
                    <CategoryAnalysisButton
                        widgetKey={`cashflow-${type}`}
                        firstCategoryId={data.data[0]?.category_id ?? null}
                    />
                </div>
            </CardHeader>
            <CardContent>
                <div className="space-y-2.5">
                    {data.data.map((item, index) => (
                        <CategoryBreakdownRow
                            key={rowKey(item)}
                            item={item}
                            index={index}
                            currencyCode={currency}
                            adapter={adapter}
                            expandable={expandable}
                            expandColumn
                            showPercentage
                            invertTrendColors={type === 'expense'}
                        />
                    ))}
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
