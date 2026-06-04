import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import { AnimatedCollapse } from '@/components/ui/animated-collapse';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { BreakdownData, BreakdownItem } from '@/hooks/use-cashflow-data';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import {
    type ExpandableCategories,
    useExpandableCategories,
} from '@/hooks/use-expandable-categories';
import { cn } from '@/lib/utils';
import {
    type CategoryColor,
    type CategoryIcon,
    getCategoryColorClasses,
} from '@/types/category';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { format } from 'date-fns';
import * as Icons from 'lucide-react';
import {
    ChevronsDown,
    ChevronsUp,
    Loader2,
    LucideIcon,
    Minus,
} from 'lucide-react';
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

interface BreakdownRowProps {
    item: BreakdownItem;
    index: number;
    type: 'income' | 'expense';
    currency: string;
    period?: { from: Date; to: Date };
    expandable: ExpandableCategories<BreakdownItem>;
}

function BreakdownRow({
    item,
    index,
    type,
    currency,
    period,
    expandable,
}: BreakdownRowProps) {
    const { categoryBarColor } = useChartColors();
    const category = item.category ?? fallbackCategory;
    const Icon = (Icons[category.icon as keyof typeof Icons] ||
        Icons.HelpCircle) as LucideIcon;

    const percentageChange =
        item.previous_amount > 0
            ? ((item.amount - item.previous_amount) / item.previous_amount) *
              100
            : null;

    const categoryColor = getCategoryColorClasses(category.color);
    const chartColor = categoryBarColor(category.color, index);

    const canExpand = Boolean(
        item.has_children && !item.is_direct && item.category_id && period,
    );
    const id = item.category_id ?? '';
    const expanded = canExpand && expandable.isExpanded(id);
    const loading = canExpand && expandable.isLoading(id);

    const categoryUrl =
        period && item.category_id
            ? transactionsIndex({
                  query: {
                      category_ids: item.category_id,
                      date_from: format(period.from, 'yyyy-MM-dd'),
                      date_to: format(period.to, 'yyyy-MM-dd'),
                  },
              }).url
            : null;

    const header = (
        <div className="flex min-w-0 items-center justify-between gap-2 overflow-hidden">
            <div className="flex max-w-[60%] grow items-center gap-2">
                <div
                    className={cn([
                        'flex size-6 shrink-0 items-center justify-center rounded-full',
                        `${categoryColor.bg} ${categoryColor.text}`,
                    ])}
                >
                    <Icon className="size-3.5" />
                </div>
                <span className="min-w-0 truncate text-sm font-medium">
                    {category.name}
                </span>
            </div>
            <div className="flex items-center gap-2">
                {percentageChange !== null && (
                    <PercentageTrendIndicator
                        trend={percentageChange}
                        label=""
                        previousAmount={item.previous_amount}
                        currentAmount={item.amount}
                        currencyCode={currency}
                        invertColors={type === 'expense'}
                        className="shrink-0 text-xs"
                    />
                )}
                <div className="flex shrink-0 items-center gap-2">
                    <span className="hidden text-xs text-muted-foreground sm:inline">
                        {item.percentage.toFixed(0)}%
                    </span>
                    <AmountDisplay
                        amountInCents={item.amount}
                        currencyCode={currency}
                        variant="compact"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                    />
                </div>
            </div>
        </div>
    );

    return (
        <div className="space-y-1.5">
            <div className="flex items-center gap-0">
                {canExpand ? (
                    <button
                        type="button"
                        onClick={() => expandable.toggle(id)}
                        aria-expanded={expanded}
                        aria-label={
                            expanded
                                ? __('Hide subcategories')
                                : __('Show subcategories')
                        }
                        className="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        {loading ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : expanded ? (
                            <ChevronsUp className="size-4" />
                        ) : (
                            <ChevronsDown className="size-4" />
                        )}
                    </button>
                ) : (
                    <span
                        aria-hidden="true"
                        className="flex size-6 shrink-0 items-center justify-center text-muted-foreground/30"
                    >
                        <Minus className="size-4" />
                    </span>
                )}
                {categoryUrl ? (
                    <Link
                        href={categoryUrl}
                        className="group block grow rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                    >
                        {header}
                    </Link>
                ) : (
                    <div className="grow px-1.5 py-1">{header}</div>
                )}
            </div>
            <Progress
                value={item.percentage}
                className="h-1.5 w-full"
                indicatorColor={chartColor}
            />

            {canExpand && (
                <AnimatedCollapse open={expanded}>
                    <div className="ml-[11px] space-y-1.5 border-l border-border pt-1.5 pl-3">
                        {expandable.getChildren(id).map((child, childIndex) => (
                            <BreakdownRow
                                key={rowKey(child)}
                                item={child}
                                index={childIndex}
                                type={type}
                                currency={currency}
                                period={period}
                                expandable={expandable}
                            />
                        ))}
                    </div>
                </AnimatedCollapse>
            )}
        </div>
    );
}

export function BreakdownCard({
    type,
    data,
    loading,
    currency = 'USD',
    period,
}: BreakdownCardProps) {
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
                        currencyCode={currency}
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        weight="semibold"
                        highlightPositive
                    />
                </div>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-2.5">
                    {data.data.map((item, index) => (
                        <BreakdownRow
                            key={rowKey(item)}
                            item={item}
                            index={index}
                            type={type}
                            currency={currency}
                            period={period}
                            expandable={expandable}
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
