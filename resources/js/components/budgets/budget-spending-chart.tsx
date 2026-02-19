import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    type ChartConfig,
} from '@/components/ui/chart';
import { useLocale } from '@/hooks/use-locale';
import { BudgetPeriod } from '@/types/budget';
import { formatCurrency } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { useMemo } from 'react';
import { Area, AreaChart, Line, ReferenceLine, XAxis } from 'recharts';

interface Props {
    currentPeriod: BudgetPeriod;
    previousPeriod?: BudgetPeriod | null;
    budgetName: string;
    currencyCode: string;
}

interface ChartDataPoint {
    day: number;
    date: string;
    spent: number;
    allocated: number;
    remaining: number;
    prevSpent?: number;
    prevDate?: string;
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: Array<{
        payload: ChartDataPoint;
    }>;
    label?: string | number;
    currencyCode: string;
    hasPreviousPeriod: boolean;
    locale: string;
}

function CustomTooltip({
    active,
    payload,
    currencyCode,
    hasPreviousPeriod,
    locale,
}: CustomTooltipProps) {
    if (!active || !payload || !payload.length) {
        return null;
    }

    const data = payload[0].payload;
    const allocated = data.allocated;
    const spent = data.spent;
    const available = data.remaining;
    const percentage =
        allocated > 0 ? Math.round((available / allocated) * 100) : 0;

    return (
        <div className="rounded-lg border bg-background p-3 shadow-lg">
            <p className="mb-2 text-sm font-medium">
                {hasPreviousPeriod
                    ? `Day ${data.day}`
                    : formatDate(data.date, 'MMM d, yyyy', locale)}
            </p>
            <div className="space-y-1 text-sm">
                <div className="flex items-center justify-between gap-8">
                    <span className="text-muted-foreground">
                        {__('Allocated:')}
                    </span>
                    <span className="font-medium">
                        {formatCurrency(allocated, currencyCode)}
                    </span>
                </div>
                <div className="flex items-center justify-between gap-8">
                    <span className="text-muted-foreground">
                        {__('Spent:')}
                    </span>
                    <span className="font-medium">
                        {formatCurrency(spent, currencyCode)}
                    </span>
                </div>
                {hasPreviousPeriod && data.prevSpent !== undefined && (
                    <div className="flex items-center justify-between gap-8">
                        <span className="text-muted-foreground">
                            {__('Last period:')}
                        </span>
                        <span className="font-medium text-muted-foreground">
                            {formatCurrency(data.prevSpent, currencyCode)}
                        </span>
                    </div>
                )}
                <div className="border-t pt-1">
                    <div className="flex items-center justify-between gap-8">
                        <span className="font-medium">{__('Available:')}</span>
                        <div className="flex items-baseline gap-1">
                            <span className="text-xs text-muted-foreground">
                                {percentage}% /
                            </span>
                            <span className="font-semibold">
                                {formatCurrency(available, currencyCode)}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function buildCumulativeSpending(period: BudgetPeriod): Map<string, number> {
    const transactions = period.budget_transactions || [];
    const transactionsByDate = new Map<string, number>();

    transactions.forEach((t) => {
        if (!t.transaction) return;
        const date = new Date(t.transaction.transaction_date)
            .toISOString()
            .split('T')[0];
        transactionsByDate.set(
            date,
            (transactionsByDate.get(date) || 0) + t.amount,
        );
    });

    return transactionsByDate;
}

export function BudgetSpendingChart({
    currentPeriod,
    previousPeriod,
    budgetName,
    currencyCode,
}: Props) {
    const locale = useLocale();
    const hasPreviousPeriod = !!previousPeriod;

    const chartData = useMemo(() => {
        const currentByDate = buildCumulativeSpending(currentPeriod);
        const prevByDate = previousPeriod
            ? buildCumulativeSpending(previousPeriod)
            : null;

        const startDate = new Date(currentPeriod.start_date);
        const endDate = new Date(currentPeriod.end_date);

        const prevStartDate = previousPeriod
            ? new Date(previousPeriod.start_date)
            : null;
        const prevEndDate = previousPeriod
            ? new Date(previousPeriod.end_date)
            : null;

        const data: ChartDataPoint[] = [];
        let cumulativeSpent = 0;
        let prevCumulativeSpent = 0;
        const currentDate = new Date(startDate);
        let dayIndex = 1;

        while (currentDate <= endDate) {
            const dateStr = currentDate.toISOString().split('T')[0];
            const dailySpent = currentByDate.get(dateStr) || 0;
            cumulativeSpent += dailySpent;

            const point: ChartDataPoint = {
                day: dayIndex,
                date: dateStr,
                spent: cumulativeSpent,
                allocated: currentPeriod.allocated_amount,
                remaining: currentPeriod.allocated_amount - cumulativeSpent,
            };

            // Map to the same day index in the previous period
            if (prevByDate && prevStartDate && prevEndDate) {
                const prevDate = new Date(prevStartDate);
                prevDate.setDate(prevDate.getDate() + dayIndex - 1);

                if (prevDate <= prevEndDate) {
                    const prevDateStr = prevDate.toISOString().split('T')[0];
                    const prevDailySpent = prevByDate.get(prevDateStr) || 0;
                    prevCumulativeSpent += prevDailySpent;
                    point.prevSpent = prevCumulativeSpent;
                    point.prevDate = prevDateStr;
                }
            }

            data.push(point);
            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        return data;
    }, [currentPeriod, previousPeriod]);

    const chartConfig = {
        spent: {
            label: 'Spent',
            color: 'var(--spent)',
        },
        allocated: {
            label: 'Budget',
            color: 'var(--allocated)',
        },
        ...(hasPreviousPeriod && {
            prevSpent: {
                label: 'Last Period',
                color: 'var(--spent-prev)',
            },
        }),
    } satisfies ChartConfig;

    const todayMarker = useMemo(() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const start = new Date(currentPeriod.start_date);
        start.setHours(0, 0, 0, 0);
        const end = new Date(currentPeriod.end_date);
        end.setHours(0, 0, 0, 0);

        if (today < start || today > end) {
            return null;
        }

        if (hasPreviousPeriod) {
            const diffMs = today.getTime() - start.getTime();
            return Math.floor(diffMs / (1000 * 60 * 60 * 24)) + 1;
        }

        return today.toISOString().split('T')[0];
    }, [currentPeriod, hasPreviousPeriod]);

    const periodLabel = useMemo(() => {
        const start = formatDate(currentPeriod.start_date, 'MMM d', locale);
        const end = formatDate(currentPeriod.end_date, 'MMM d, yyyy', locale);
        return `${start} - ${end}`;
    }, [currentPeriod, locale]);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__('Budget Spending')}</CardTitle>
                <CardDescription>
                    {__('Tracking spending for')}
                    {budgetName} · {periodLabel}
                </CardDescription>
            </CardHeader>
            <CardContent className="px-6 pt-0">
                <ChartContainer
                    config={chartConfig}
                    className="h-[300px] w-full"
                >
                    <AreaChart
                        className="overflow-hidden rounded"
                        data={chartData}
                        margin={{ top: 0, right: 0, bottom: 0, left: 0 }}
                    >
                        <defs>
                            <linearGradient
                                id="fillSpent"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor="var(--color-spent)"
                                    stopOpacity={0.8}
                                />

                                <stop
                                    offset="50%"
                                    stopColor="var(--color-spent)"
                                    stopOpacity={0.4}
                                />

                                <stop
                                    offset="100%"
                                    stopColor="var(--color-spent)"
                                    stopOpacity={0.05}
                                />
                            </linearGradient>
                            <linearGradient
                                id="fillAllocated"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor="var(--color-allocated)"
                                    stopOpacity={0.4}
                                />

                                <stop
                                    offset="50%"
                                    stopColor="var(--color-allocated)"
                                    stopOpacity={0.2}
                                />

                                <stop
                                    offset="100%"
                                    stopColor="var(--color-allocated)"
                                    stopOpacity={0.05}
                                />
                            </linearGradient>
                        </defs>
                        <XAxis
                            dataKey={hasPreviousPeriod ? 'day' : 'date'}
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            tickFormatter={(value) => {
                                if (hasPreviousPeriod) {
                                    return `Day ${value}`;
                                }
                                return formatDate(value, 'MMM d', locale);
                            }}
                        />

                        <ChartTooltip
                            content={
                                <CustomTooltip
                                    currencyCode={currencyCode}
                                    hasPreviousPeriod={hasPreviousPeriod}
                                    locale={locale}
                                />
                            }
                        />

                        {todayMarker !== null && (
                            <ReferenceLine
                                x={todayMarker}
                                stroke="var(--color-foreground)"
                                strokeWidth={1}
                                strokeDasharray="4 4"
                                label={{
                                    value: __('Today'),
                                    position: 'top',
                                    fontSize: 12,
                                    fill: 'var(--color-muted-foreground)',
                                }}
                            />
                        )}

                        <Area
                            dataKey="allocated"
                            type="basis"
                            fill="url(#fillAllocated)"
                            stroke="var(--color-allocated)"
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 6 }}
                            fillOpacity={1}
                        />

                        <Area
                            dataKey="spent"
                            type="basis"
                            fill="url(#fillSpent)"
                            stroke="var(--color-spent)"
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 6 }}
                            fillOpacity={1}
                        />
                        {hasPreviousPeriod && (
                            <Line
                                dataKey="prevSpent"
                                type="basis"
                                stroke="var(--color-prevSpent)"
                                strokeWidth={2}
                                strokeDasharray="6 4"
                                dot={false}
                                activeDot={{ r: 4 }}
                                connectNulls={false}
                            />
                        )}
                    </AreaChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}
