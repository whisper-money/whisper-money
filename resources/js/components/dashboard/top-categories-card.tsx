import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
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
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import {
    type ExpandableCategories,
    useExpandableCategories,
} from '@/hooks/use-expandable-categories';
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
import {
    ChevronsDown,
    ChevronsUp,
    Loader2,
    LucideIcon,
    Minus,
} from 'lucide-react';
import { useCallback, useMemo } from 'react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

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

interface CategoryRowProps {
    item: CategoryData;
    index: number;
    currencyCode: string;
    dateFrom: string;
    dateTo: string;
    expandable: ExpandableCategories<CategoryData>;
}

function CategoryRow({
    item,
    index,
    currencyCode,
    dateFrom,
    dateTo,
    expandable,
}: CategoryRowProps) {
    const { categoryBarColor } = useChartColors();
    const category = item.category;
    const categoryId = category?.id ?? item.category_id ?? 'uncategorized';
    const categoryName = category?.name ?? __('Uncategorized');
    const categoryIcon = category?.icon ?? 'HelpCircle';
    const categoryColorName = category?.color ?? ('gray' as CategoryColor);
    const Icon = (Icons[categoryIcon as keyof typeof Icons] ||
        Icons.HelpCircle) as LucideIcon;

    const percentageChange =
        item.previous_amount > 0
            ? ((item.amount - item.previous_amount) / item.previous_amount) *
              100
            : null;
    const percentage =
        item.total_amount > 0 ? (item.amount / item.total_amount) * 100 : 0;
    const categoryColor = getCategoryColorClasses(categoryColorName);
    const chartColor = categoryBarColor(categoryColorName, index);

    const canExpand = Boolean(item.has_children && !item.is_direct && category);
    const expanded = canExpand && expandable.isExpanded(categoryId);
    const loading = canExpand && expandable.isLoading(categoryId);

    const categoryUrl = transactionsIndex({
        query: {
            category_ids: categoryId,
            date_from: dateFrom,
            date_to: dateTo,
        },
    }).url;

    const header = (
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
                    previousAmount={item.previous_amount}
                    currentAmount={item.amount}
                    currencyCode={currencyCode}
                    invertColors
                    className="shrink-0 text-xs"
                />
            )}
            <AmountDisplay
                amountInCents={item.amount}
                currencyCode={currencyCode}
                variant="compact"
                minimumFractionDigits={0}
                maximumFractionDigits={0}
                className="shrink-0"
            />
        </div>
    );

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-0">
                {canExpand ? (
                    <button
                        type="button"
                        onClick={() => expandable.toggle(categoryId)}
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
                <Link
                    href={categoryUrl}
                    className="group block grow rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                >
                    {header}
                </Link>
            </div>
            <Progress
                value={percentage}
                className="h-2 w-full"
                indicatorColor={chartColor}
            />

            {canExpand && (
                <AnimatedCollapse open={expanded}>
                    <div className="ml-[11px] space-y-2 border-l border-border pt-2 pl-3">
                        {expandable
                            .getChildren(categoryId)
                            .map((child, childIndex) => (
                                <CategoryRow
                                    key={rowKey(child)}
                                    item={child}
                                    index={childIndex}
                                    currencyCode={currencyCode}
                                    dateFrom={dateFrom}
                                    dateTo={dateTo}
                                    expandable={expandable}
                                />
                            ))}
                    </div>
                </AnimatedCollapse>
            )}
        </div>
    );
}

export function TopCategoriesCard({
    categories,
    loading,
}: TopCategoriesCardProps) {
    const { auth } = usePage<SharedData>().props;

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
                <CardTitle>{__('Top spending categories')}</CardTitle>
                <CardDescription>{__('on the last 30 days')}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {categories.map((item, index) => (
                        <CategoryRow
                            key={rowKey(item)}
                            item={item}
                            index={index}
                            currencyCode={auth.user.currency_code}
                            dateFrom={dateFrom}
                            dateTo={dateTo}
                            expandable={expandable}
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
