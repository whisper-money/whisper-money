import { AmountDisplay } from '@/components/ui/amount-display';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { useLocale } from '@/hooks/use-locale';
import {
    serializeFilters,
    type SerializedFilters,
} from '@/lib/transaction-filter-serialization';
import { cn } from '@/lib/utils';
import { type TransactionFilters } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { useCallback, useEffect, useState } from 'react';
import {
    Bar,
    Cell,
    ComposedChart,
    Line,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface AnalysisSummary {
    income: number;
    expense: number;
    net: number;
    count: number;
    days: number;
    average_expense_per_day: number;
}

interface CategorySlice {
    category_id: string | null;
    name: string;
    color: string;
    amount: number;
}

interface TagSlice {
    id: string;
    name: string;
    color: string;
    amount: number;
}

interface OverTimePoint {
    date: string;
    label: string;
    income: number;
    expense: number;
    cumulative_expense: number;
}

interface AnalysisData {
    currency: string;
    summary: AnalysisSummary;
    by_category: CategorySlice[];
    distinct_category_count: number;
    by_tag: TagSlice[];
    distinct_label_count: number;
    over_time: { bucket: 'day' | 'month'; points: OverTimePoint[] };
}

interface TransactionAnalysisDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: TransactionFilters;
}

const CHART_PALETTE = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
    'var(--color-chart-5)',
    'var(--color-chart-6)',
    'var(--color-chart-7)',
    'var(--color-chart-8)',
];

function buildQueryString(filters: SerializedFilters): string {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            if (value.length > 0) {
                params.set(key, value.join(','));
            }
        } else if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    });

    return params.toString();
}

export function TransactionAnalysisDrawer({
    open,
    onOpenChange,
    filters,
}: TransactionAnalysisDrawerProps) {
    const locale = useLocale();
    const [data, setData] = useState<AnalysisData | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadAnalysis = useCallback(async () => {
        setIsLoading(true);
        setError(null);

        try {
            const query = buildQueryString(serializeFilters(filters));
            const response = await fetch(
                `/api/transactions/analysis?${query}`,
                {
                    headers: { Accept: 'application/json' },
                },
            );

            if (!response.ok) {
                throw new Error('Request failed');
            }

            setData((await response.json()) as AnalysisData);
        } catch {
            setError(__('Could not load the analysis. Please try again.'));
        } finally {
            setIsLoading(false);
        }
    }, [filters]);

    useEffect(() => {
        if (open) {
            void loadAnalysis();
        }
    }, [open, loadAnalysis]);

    const currency = data?.currency ?? '';
    const hasTransactions = (data?.summary.count ?? 0) > 0;

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
                <div className="mx-auto w-full max-w-5xl overflow-y-auto p-6">
                    <DrawerHeader className="px-0">
                        <DrawerTitle>{__('Analysis')}</DrawerTitle>
                        <DrawerDescription>
                            {__(
                                'A breakdown of the transactions matching your current filters.',
                            )}
                        </DrawerDescription>
                    </DrawerHeader>

                    {isLoading && <AnalysisSkeleton />}

                    {!isLoading && error && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {error}
                        </p>
                    )}

                    {!isLoading && !error && !hasTransactions && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {__('No transactions match the current filters.')}
                        </p>
                    )}

                    {!isLoading && !error && data && hasTransactions && (
                        <div className="flex flex-col gap-8">
                            <SummaryCards
                                summary={data.summary}
                                currency={currency}
                            />

                            <OverTimeChart
                                points={data.over_time.points}
                                currency={currency}
                                locale={locale}
                            />

                            {data.distinct_category_count > 1 && (
                                <CategoryBreakdown
                                    slices={data.by_category}
                                    currency={currency}
                                />
                            )}

                            {data.distinct_label_count > 1 && (
                                <TagBreakdown
                                    slices={data.by_tag}
                                    currency={currency}
                                    locale={locale}
                                />
                            )}
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
    summary: AnalysisSummary;
    currency: string;
}) {
    const cards = [
        { label: __('Income'), amount: summary.income, tone: 'income' },
        { label: __('Expenses'), amount: summary.expense, tone: 'expense' },
        { label: __('Net'), amount: summary.net, tone: 'net' },
        {
            label: __('Avg / day'),
            amount: summary.average_expense_per_day,
            tone: 'expense',
        },
    ] as const;

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {cards.map((card) => (
                <div key={card.label} className="rounded-lg border bg-card p-4">
                    <p className="text-xs text-muted-foreground">
                        {card.label}
                    </p>
                    <AmountDisplay
                        amountInCents={card.amount}
                        currencyCode={currency}
                        className={cn(
                            'mt-1 text-lg font-semibold tabular-nums',
                            card.tone === 'income' && 'text-emerald-600',
                            card.tone === 'expense' && 'text-red-600',
                        )}
                    />
                </div>
            ))}
            <p className="col-span-2 text-xs text-muted-foreground sm:col-span-4">
                {summary.count} {__('transactions')} · {summary.days}{' '}
                {__('days')}
            </p>
        </div>
    );
}

function OverTimeChart({
    points,
    currency,
    locale,
}: {
    points: OverTimePoint[];
    currency: string;
    locale: string;
}) {
    const config: ChartConfig = {
        income: { label: __('Income'), color: 'var(--color-chart-2)' },
        expense: { label: __('Expenses'), color: 'var(--color-chart-5)' },
        cumulative_expense: {
            label: __('Cumulative spend'),
            color: 'var(--color-chart-1)',
        },
    };

    const compact = (value: number) =>
        new Intl.NumberFormat(locale, {
            notation: 'compact',
            compactDisplay: 'short',
        }).format(value / 100);

    return (
        <section className="flex flex-col gap-3">
            <h3 className="text-sm font-medium">{__('Spending over time')}</h3>
            <ChartContainer config={config} className="h-64 w-full">
                <ComposedChart data={points}>
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        minTickGap={16}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        width={48}
                        tickFormatter={compact}
                    />
                    <Tooltip
                        content={<OverTimeTooltip currency={currency} />}
                        cursor={{ fill: 'var(--color-muted)', opacity: 0.3 }}
                    />
                    <Bar
                        dataKey="income"
                        fill="var(--color-chart-2)"
                        radius={[3, 3, 0, 0]}
                    />
                    <Bar
                        dataKey="expense"
                        fill="var(--color-chart-5)"
                        radius={[3, 3, 0, 0]}
                    />
                    <Line
                        type="monotone"
                        dataKey="cumulative_expense"
                        stroke="var(--color-chart-1)"
                        strokeWidth={2}
                        dot={false}
                    />
                </ComposedChart>
            </ChartContainer>
        </section>
    );
}

interface TooltipPayloadItem {
    name?: string;
    dataKey?: string;
    value?: number;
    payload?: OverTimePoint;
}

function OverTimeTooltip({
    active,
    payload,
    currency,
}: {
    active?: boolean;
    payload?: TooltipPayloadItem[];
    currency: string;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const point = payload[0]?.payload;
    const rows: {
        label: string;
        key: 'income' | 'expense' | 'cumulative_expense';
    }[] = [
        { label: __('Income'), key: 'income' },
        { label: __('Expenses'), key: 'expense' },
        { label: __('Cumulative spend'), key: 'cumulative_expense' },
    ];

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{point?.label}</div>
            {rows.map((row) => (
                <div
                    key={row.key}
                    className="mt-1 flex items-center justify-between gap-4"
                >
                    <span className="text-muted-foreground">{row.label}</span>
                    <AmountDisplay
                        amountInCents={point ? point[row.key] : 0}
                        currencyCode={currency}
                        className="font-mono tabular-nums"
                    />
                </div>
            ))}
        </div>
    );
}

function CategoryBreakdown({
    slices,
    currency,
}: {
    slices: CategorySlice[];
    currency: string;
}) {
    const total = slices.reduce((sum, slice) => sum + slice.amount, 0);
    const config: ChartConfig = { amount: { label: __('Spent') } };

    return (
        <section className="flex flex-col gap-3">
            <h3 className="text-sm font-medium">
                {__('Spending by category')}
            </h3>
            <div className="flex flex-col items-center gap-6 sm:flex-row">
                <ChartContainer config={config} className="h-52 w-52 shrink-0">
                    <ResponsiveContainer>
                        <PieChart>
                            <Pie
                                data={slices}
                                dataKey="amount"
                                nameKey="name"
                                innerRadius={55}
                                outerRadius={85}
                                paddingAngle={2}
                            >
                                {slices.map((slice, index) => (
                                    <Cell
                                        key={
                                            slice.category_id ??
                                            `slice-${index}`
                                        }
                                        fill={
                                            CHART_PALETTE[
                                                index % CHART_PALETTE.length
                                            ]
                                        }
                                    />
                                ))}
                            </Pie>
                        </PieChart>
                    </ResponsiveContainer>
                </ChartContainer>

                <ul className="flex w-full flex-col gap-2">
                    {slices.map((slice, index) => (
                        <li
                            key={slice.category_id ?? `row-${index}`}
                            className="flex items-center gap-3 text-sm"
                        >
                            <span
                                className="h-3 w-3 shrink-0 rounded-full"
                                style={{
                                    backgroundColor:
                                        CHART_PALETTE[
                                            index % CHART_PALETTE.length
                                        ],
                                }}
                            />
                            <span className="flex-1 truncate">
                                {slice.name}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {total > 0
                                    ? Math.round((slice.amount / total) * 100)
                                    : 0}
                                %
                            </span>
                            <AmountDisplay
                                amountInCents={slice.amount}
                                currencyCode={currency}
                                className="font-mono tabular-nums"
                            />
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

function TagBreakdown({
    slices,
    currency,
    locale,
}: {
    slices: TagSlice[];
    currency: string;
    locale: string;
}) {
    const config: ChartConfig = {
        amount: { label: __('Spent'), color: 'var(--color-chart-1)' },
    };

    const compact = (value: number) =>
        new Intl.NumberFormat(locale, {
            notation: 'compact',
            compactDisplay: 'short',
        }).format(value / 100);

    return (
        <section className="flex flex-col gap-3">
            <h3 className="text-sm font-medium">{__('Spending by tag')}</h3>
            <ChartContainer
                config={config}
                className="w-full"
                style={{ height: `${Math.max(slices.length * 44, 88)}px` }}
            >
                <ResponsiveContainer>
                    <ComposedChart
                        layout="vertical"
                        data={slices}
                        margin={{ left: 8, right: 16 }}
                    >
                        <XAxis type="number" hide tickFormatter={compact} />
                        <YAxis
                            type="category"
                            dataKey="name"
                            tickLine={false}
                            axisLine={false}
                            width={96}
                        />
                        <Tooltip
                            cursor={{
                                fill: 'var(--color-muted)',
                                opacity: 0.3,
                            }}
                            content={<TagTooltip currency={currency} />}
                        />
                        <Bar
                            dataKey="amount"
                            fill="var(--color-chart-1)"
                            radius={[0, 3, 3, 0]}
                        />
                    </ComposedChart>
                </ResponsiveContainer>
            </ChartContainer>
        </section>
    );
}

function TagTooltip({
    active,
    payload,
    currency,
}: {
    active?: boolean;
    payload?: { payload?: TagSlice }[];
    currency: string;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const slice = payload[0]?.payload;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{slice?.name}</div>
            <AmountDisplay
                amountInCents={slice?.amount ?? 0}
                currencyCode={currency}
                className="mt-1 font-mono tabular-nums"
            />
        </div>
    );
}

function AnalysisSkeleton() {
    return (
        <div className="flex animate-pulse flex-col gap-8">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {Array.from({ length: 4 }).map((_, index) => (
                    <div
                        key={index}
                        className="h-20 rounded-lg border bg-muted/50"
                    />
                ))}
            </div>
            <div className="h-64 rounded-lg border bg-muted/50" />
            <div className="h-52 rounded-lg border bg-muted/50" />
        </div>
    );
}
