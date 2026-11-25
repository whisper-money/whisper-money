import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { CategoryAnalysisButton } from '@/components/categories/category-analysis-button';
import { PrivacyRevealButton } from '@/components/privacy-reveal-button';
import {
    CategoryBreakdownRow,
    trendFrom,
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
import { EmptyState, EmptyStateDescription } from '@/components/ui/empty-state';
import { useExpandableCategories } from '@/hooks/use-expandable-categories';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { categoryChartColor } from '@/lib/chart-colors';
import { fetchJson } from '@/lib/fetch-json';
import { captureEvent } from '@/lib/posthog';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import {
    Category,
    getCategoryColorClasses,
    type CategoryColor,
} from '@/types/category';
import { toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import { HelpCircle, LucideIcon, Minus } from 'lucide-react';
import { useCallback } from 'react';

export interface CategoryData {
    category: Category | null;
    category_id?: string | null;
    amount: number;
    previous_amount: number;
    total_amount: number;
    has_children?: boolean;
    is_direct?: boolean;
}

/**
 * The owner's current month, as the server summed it: the same days and rules
 * as the cashflow card, so `categories` plus `uncategorized` add up to `total`.
 */
export interface TopCategoriesData {
    /** The calendar month, and the owner's today inside it. */
    period: { from: string; to: string; through: string };
    total: number;
    categories: CategoryData[];
    uncategorized: { amount: number; count: number };
    /** Onboarding estimates in the month: the cashflow card counts them, this one does not. */
    leaves_out_estimates: boolean;
}

/** The transactions filter's id for "no category". */
const UNCATEGORIZED = 'uncategorized';

interface TopCategoriesCardProps {
    data?: TopCategoriesData | null;
    loading?: boolean;
}

function rowKey(item: CategoryData): string {
    return `${item.category?.id ?? item.category_id ?? UNCATEGORIZED}:${item.is_direct ? 'direct' : 'node'}`;
}

/**
 * The transactions list over the card's month, filtered to one category or to
 * the ones with none: the same days the card summed. With none, only money
 * going out, which is what the uncategorized row counts; uncategorized income
 * is not spending.
 */
function transactionsHref(
    categoryId: string,
    period: TopCategoriesData['period'],
): string {
    return transactionsIndex({
        query: {
            category_ids: categoryId,
            date_from: period.from,
            date_to: period.to,
            ...(categoryId === UNCATEGORIZED && { amount_max: 0 }),
        },
    }).url;
}

/** "1 – 24 Nov": the month so far, in the reader's locale. */
function monthSoFar(period: TopCategoriesData['period'], locale: string) {
    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
    }).formatRange(toLocalDate(period.from), toLocalDate(period.through));
}

export function TopCategoriesCard({ data, loading }: TopCategoriesCardProps) {
    const { auth } = usePage<SharedData>().props;
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();

    const dateFrom = data?.period.from ?? '';
    const dateTo = data?.period.to ?? '';

    const fetchChildren = useCallback(
        async (categoryId: string): Promise<CategoryData[]> => {
            const params = new URLSearchParams({
                from: dateFrom,
                to: dateTo,
                parent: categoryId,
            });
            return fetchJson<CategoryData[]>(
                `/api/dashboard/top-categories?${params.toString()}`,
            );
        },
        [dateFrom, dateTo],
    );

    const expandable = useExpandableCategories<CategoryData>(
        fetchChildren,
        dateFrom,
    );

    const adapter: CategoryBreakdownAdapter<CategoryData> = {
        getId: (item) => item.category?.id ?? item.category_id ?? UNCATEGORIZED,
        getKey: (item) => rowKey(item),
        getName: (item) => item.category?.name ?? __('Uncategorized'),
        getAmount: (item) => item.amount,
        getPercentage: (item) =>
            item.total_amount > 0 ? (item.amount / item.total_amount) * 100 : 0,
        getBarColor: (item, index) =>
            categoryChartColor(
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
            data
                ? transactionsHref(
                      item.category?.id ?? item.category_id ?? UNCATEGORIZED,
                      data.period,
                  )
                : null,
        getTrend: (item) => trendFrom(item.amount, item.previous_amount),
        canExpand: (item) =>
            Boolean(item.has_children && !item.is_direct && item.category),
    };

    if (loading || !auth?.user || !data) {
        return (
            <Card className="w-full">
                <CardHeader>
                    <CardTitle>{__('Spending this month')}</CardTitle>
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

    const { categories, uncategorized, total, period, leaves_out_estimates } =
        data;
    const currencyCode = auth.user.currency_code;
    const showsUncategorized =
        uncategorized.count > 0 && uncategorized.amount > 0;
    // Only claimed when it holds: a category a refund netted below zero, or
    // one past the top ten, is in the total but not on a row; and the
    // cashflow card counts the onboarding estimates this card leaves out.
    const rowsAddUp =
        total > 0 &&
        !leaves_out_estimates &&
        categories.reduce((sum, item) => sum + item.amount, 0) +
            (showsUncategorized ? uncategorized.amount : 0) ===
            total;

    return (
        <Card className="w-full">
            <CardHeader className="gap-2">
                <div className="flex items-start justify-between gap-2">
                    <CardTitle>{__('Spending this month')}</CardTitle>
                    <div className="flex items-center gap-2">
                        <PrivacyRevealButton className="-my-2" />
                        <CategoryAnalysisButton
                            widgetKey="dashboard-top-categories"
                            firstCategoryId={
                                categories[0]?.category?.id ??
                                categories[0]?.category_id ??
                                null
                            }
                        />
                    </div>
                </div>
                <CardDescription>
                    {monthSoFar(period, locale)} ·{' '}
                    <AmountDisplay
                        amountInCents={total}
                        currencyCode={currencyCode}
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                    />
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-3">
                    {categories.map((item, index) => (
                        <CategoryBreakdownRow
                            key={rowKey(item)}
                            item={item}
                            index={index}
                            currencyCode={currencyCode}
                            adapter={adapter}
                            expandable={expandable}
                            expandColumn
                        />
                    ))}
                    {showsUncategorized && (
                        <UncategorizedRow
                            amount={uncategorized.amount}
                            count={uncategorized.count}
                            percentage={
                                total > 0
                                    ? (uncategorized.amount / total) * 100
                                    : 0
                            }
                            currencyCode={currencyCode}
                            href={transactionsHref(UNCATEGORIZED, period)}
                        />
                    )}
                    {categories.length === 0 && !showsUncategorized && (
                        <EmptyState size="inline">
                            <EmptyStateDescription>
                                {__('No spending data this month')}
                            </EmptyStateDescription>
                        </EmptyState>
                    )}
                    {rowsAddUp && (
                        <p className="text-xs text-muted-foreground">
                            {__(
                                'Same period as Cashflow, so the rows add up to :amount.',
                                {
                                    amount: formatAmount(total, currencyCode, {
                                        fractionDigits: 0,
                                    }),
                                },
                            )}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * What the month spent with no category yet: a row like the others, so the
 * card adds up, but neutral and hatched rather than in a category's color, and
 * with the way out of it.
 */
function UncategorizedRow({
    amount,
    count,
    percentage,
    currencyCode,
    href,
}: {
    amount: number;
    count: number;
    percentage: number;
    currencyCode: string;
    href: string;
}) {
    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center">
                <span
                    aria-hidden="true"
                    className="flex size-6 shrink-0 items-center justify-center text-muted-foreground/30"
                >
                    <Minus className="size-4" />
                </span>
                <div className="flex min-w-0 grow items-center gap-2 px-1.5 py-1">
                    <div className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <HelpCircle className="size-4" />
                    </div>
                    <span className="min-w-0 flex-1 truncate text-sm font-medium">
                        {__('Uncategorized')} ·{' '}
                        <span className="font-normal text-muted-foreground">
                            {count === 1
                                ? __('1 movement')
                                : __(':count movements', { count })}
                        </span>
                    </span>
                    <AmountDisplay
                        amountInCents={amount}
                        currencyCode={currencyCode}
                        variant="compact"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        className="shrink-0 whitespace-nowrap"
                    />
                </div>
            </div>
            <div
                data-testid="uncategorized-bar"
                className="h-2 w-full overflow-hidden rounded-full bg-secondary"
            >
                <div
                    className="h-full rounded-full border border-muted-foreground/40 bg-muted"
                    style={{
                        width: `${Math.min(100, Math.max(0, percentage))}%`,
                        backgroundImage:
                            'repeating-linear-gradient(135deg, transparent 0 3px, color-mix(in oklab, var(--muted-foreground) 45%, transparent) 3px 4px)',
                    }}
                />
            </div>
            <Link
                href={href}
                onClick={() =>
                    captureEvent('dashboard_uncategorized_categorize_clicked', {
                        uncategorized_count: count,
                    })
                }
                className="self-start text-xs font-medium text-muted-foreground underline underline-offset-4 hover:text-foreground"
            >
                {__('Categorize these')}
            </Link>
        </div>
    );
}
