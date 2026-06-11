import { CategoryCombobox } from '@/components/shared/category-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import { ChartConfig } from '@/components/ui/chart';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { StackedBarChart } from '@/components/ui/stacked-bar-chart';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { SharedData } from '@/types';
import { type Category } from '@/types/category';
import { formatCurrency } from '@/utils/currency';
import { formatMonthFromYearMonth } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { Minus, TrendingDown, TrendingUp } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

export const CATEGORY_ANALYSIS_STORAGE_PREFIX = 'wm.category-analysis.';

interface BreakdownSeries {
    key: string;
    label: string;
}

interface MonthPoint {
    key: string;
    [seriesKey: string]: number | string;
}

interface BreakdownSummary {
    average_per_month: number;
    trend_percentage: number | null;
}

interface BreakdownData {
    currency: string;
    category: { id: string; name: string };
    series: BreakdownSeries[];
    months: MonthPoint[];
    summary: BreakdownSummary;
}

interface CategoryAnalysisDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Distinct localStorage slot so each widget remembers its own category. */
    widgetKey: string;
    /** Category prefilled the first time the widget is opened. */
    firstCategoryId?: string | null;
}

/**
 * Resolves the category to show when the drawer opens: the one this widget
 * remembered last, falling back to the widget's first category when nothing
 * valid is stored (a remembered category that was since deleted is ignored).
 */
export function resolveInitialCategory(
    widgetKey: string,
    firstCategoryId: string | null | undefined,
    categories: Category[],
): string | null {
    const stored = localStorage.getItem(
        `${CATEGORY_ANALYSIS_STORAGE_PREFIX}${widgetKey}`,
    );

    if (stored && categories.some((category) => category.id === stored)) {
        return stored;
    }

    if (firstCategoryId && categories.some((c) => c.id === firstCategoryId)) {
        return firstCategoryId;
    }

    return null;
}

export function CategoryAnalysisDrawer({
    open,
    onOpenChange,
    widgetKey,
    firstCategoryId,
}: CategoryAnalysisDrawerProps) {
    const locale = useLocale();
    const { categories = [] } = usePage<
        SharedData & { categories: Category[] }
    >().props;

    const [categoryId, setCategoryId] = useState<string | null>(null);
    const [data, setData] = useState<BreakdownData | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setCategoryId(
                resolveInitialCategory(widgetKey, firstCategoryId, categories),
            );
        }
        // Re-resolve only when the drawer is (re)opened.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, widgetKey, firstCategoryId]);

    const selectCategory = useCallback(
        (next: string) => {
            if (next === 'null') {
                return;
            }

            setCategoryId(next);
            localStorage.setItem(
                `${CATEGORY_ANALYSIS_STORAGE_PREFIX}${widgetKey}`,
                next,
            );
        },
        [widgetKey],
    );

    useEffect(() => {
        if (!open || !categoryId) {
            return;
        }

        let active = true;
        setIsLoading(true);
        setError(null);

        fetch(`/api/categories/${categoryId}/monthly-breakdown`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            })
            .then((json: BreakdownData) => {
                if (active) {
                    setData(json);
                }
            })
            .catch(() => {
                if (active) {
                    setError(
                        __('Could not load the analysis. Please try again.'),
                    );
                }
            })
            .finally(() => {
                if (active) {
                    setIsLoading(false);
                }
            });

        return () => {
            active = false;
        };
    }, [open, categoryId]);

    const currency = data?.currency ?? '';
    const dataKeys = useMemo(
        () => data?.series.map((series) => series.key) ?? [],
        [data],
    );
    const config = useMemo<ChartConfig>(
        () =>
            Object.fromEntries(
                (data?.series ?? []).map((series) => [
                    series.key,
                    { label: series.label },
                ]),
            ),
        [data],
    );

    const valueFormatter = useCallback(
        (value: number) => formatCurrency(value, currency, locale, 0, 0),
        [currency, locale],
    );

    const xAxisFormatter = useCallback(
        (value: string) => formatMonthFromYearMonth(value, locale),
        [locale],
    );

    const hasData = data !== null && data.series.length > 0;

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 overflow-y-auto p-6">
                    <DrawerHeader className="gap-2 px-0 text-center">
                        <DrawerTitle className="text-xl">
                            {__('Category analysis')}
                        </DrawerTitle>
                        <DrawerDescription className="text-sm text-pretty">
                            {__(
                                'How much you spent on this category each month over the last 12 months.',
                            )}
                        </DrawerDescription>
                    </DrawerHeader>

                    <CategoryCombobox
                        value={categoryId}
                        onValueChange={selectCategory}
                        categories={categories}
                        showUncategorized={false}
                        placeholder={__('Select a category')}
                    />

                    {isLoading && (
                        <div className="h-[320px] w-full animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700" />
                    )}

                    {!isLoading && error && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {error}
                        </p>
                    )}

                    {!isLoading && !error && !categoryId && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {__('Pick a category to see its monthly spending.')}
                        </p>
                    )}

                    {!isLoading && !error && categoryId && !hasData && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {__('No spending in the last 12 months.')}
                        </p>
                    )}

                    {!isLoading && !error && hasData && (
                        <div className="flex flex-col gap-6">
                            <SummaryCards
                                summary={data.summary}
                                currency={currency}
                            />

                            <Card className="py-4">
                                <CardContent>
                                    <StackedBarChart
                                        data={data.months}
                                        dataKeys={dataKeys}
                                        config={config}
                                        xAxisKey="key"
                                        xAxisFormatter={xAxisFormatter}
                                        valueFormatter={valueFormatter}
                                        displayCurrency={currency}
                                        className="h-[320px] w-full"
                                    />
                                </CardContent>
                            </Card>
                        </div>
                    )}
                </div>
            </DrawerContent>
        </Drawer>
    );
}

function SummaryCards({
    summary,
    currency,
}: {
    summary: BreakdownSummary;
    currency: string;
}) {
    const trend = summary.trend_percentage;
    const TrendIcon =
        trend === null || trend === 0
            ? Minus
            : trend > 0
              ? TrendingUp
              : TrendingDown;

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Card className="gap-0 py-4">
                <CardContent className="flex flex-col gap-1">
                    <p className="text-xs text-muted-foreground">
                        {__('Monthly average')}
                    </p>
                    <AmountDisplay
                        amountInCents={summary.average_per_month}
                        currencyCode={currency}
                        className="text-lg font-semibold tabular-nums"
                    />
                </CardContent>
            </Card>

            <Card className="gap-0 py-4">
                <CardContent className="flex flex-col gap-1">
                    <p className="text-xs text-muted-foreground">
                        {__('Trend')}
                    </p>
                    {trend === null ? (
                        <p className="text-lg font-semibold text-muted-foreground">
                            {__('Not enough history')}
                        </p>
                    ) : (
                        <div className="flex items-center gap-1.5">
                            <TrendIcon
                                className={cn(
                                    'size-4',
                                    trend > 0 && 'text-amber-500',
                                    trend < 0 && 'text-emerald-500',
                                    trend === 0 && 'text-muted-foreground',
                                )}
                            />
                            <span className="text-lg font-semibold tabular-nums">
                                {trend > 0 ? '+' : ''}
                                {trend}%
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {__('vs. previous 6 months')}
                            </span>
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
