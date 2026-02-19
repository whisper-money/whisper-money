import { AccountName } from '@/components/accounts/account-name';
import {
    type ChartGranularity,
    ChartGranularityToggle,
    ChartViewToggle,
    MoMChart,
    MoMPercentChart,
} from '@/components/charts';
import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartConfig,
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import {
    convertSingleAccountData,
    useChartViews,
} from '@/hooks/use-chart-views';
import { useLocale } from '@/hooks/use-locale';
import { Account } from '@/types/account';
import { formatDayFromDate, formatMonthFromYearMonth } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { format, subDays, subMonths } from 'date-fns';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Area, AreaChart, Bar, BarChart, XAxis } from 'recharts';

const DAILY_DAYS = 30;

interface BalanceDataPoint {
    month: string;
    timestamp: number;
    value: number;
}

interface AccountBalanceData {
    data: BalanceDataPoint[];
    account: {
        id: string;
        name: string;
        name_iv: string;
        type: string;
        currency_code: string;
    };
}

interface DailyBalanceDataPoint {
    date: string;
    timestamp: number;
    value: number;
}

interface AccountDailyBalanceData {
    data: DailyBalanceDataPoint[];
    account: {
        id: string;
        name: string;
        name_iv: string;
        type: string;
        currency_code: string;
    };
}

interface AccountBalanceChartProps {
    account: Account;
    loading?: boolean;
    refreshKey?: number;
    onBalanceClick?: () => void;
}

function createXAxisFormatter(locale: string, granularity: ChartGranularity) {
    if (granularity === 'daily') {
        return function formatXAxisLabel(value: string): string {
            return formatDayFromDate(value, locale);
        };
    }
    return function formatXAxisLabel(value: string): string {
        return formatMonthFromYearMonth(value, locale);
    };
}

function formatChartCurrency(
    value: number,
    currencyCode: string,
    locale: string,
): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value / 100);
}

function calculateTrend(
    data: Array<{ value: number }>,
    periodsBack: number,
): { percentage: number; previousValue: number; currentValue: number } | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - periodsBack);

    if (currentIndex === previousIndex) return null;

    const currentValue = data[currentIndex].value;
    const previousValue = data[previousIndex].value;

    if (previousValue === 0) return null;

    return {
        percentage:
            ((currentValue - previousValue) / Math.abs(previousValue)) * 100,
        previousValue,
        currentValue,
    };
}

/**
 * Normalize daily data points to use "month" key so they work with
 * convertSingleAccountData and useChartViews without changes.
 */
function normalizeDailyData(data: DailyBalanceDataPoint[]): BalanceDataPoint[] {
    return data.map((point) => ({
        month: point.date,
        timestamp: point.timestamp,
        value: point.value,
    }));
}

export function AccountBalanceChart({
    account,
    loading: initialLoading,
    refreshKey,
    onBalanceClick,
}: AccountBalanceChartProps) {
    const locale = useLocale();
    const [granularity, setGranularity] = useState<ChartGranularity>('monthly');
    const [balanceData, setBalanceData] = useState<AccountBalanceData | null>(
        null,
    );
    const [isLoading, setIsLoading] = useState(true);

    const fetchBalanceData = useCallback(
        async (currentGranularity: ChartGranularity) => {
            setIsLoading(true);
            try {
                const now = new Date();
                const to = format(now, 'yyyy-MM-dd');

                if (currentGranularity === 'daily') {
                    // Fetch DAILY_DAYS + 1 days (extra day for DoD baseline)
                    const from = format(subDays(now, DAILY_DAYS), 'yyyy-MM-dd');
                    const params = new URLSearchParams({ from, to });
                    const response = await fetch(
                        `/api/dashboard/account/${account.id}/daily-balance-evolution?${params.toString()}`,
                    );
                    const data: AccountDailyBalanceData = await response.json();
                    // Normalize daily data so the rest of the component works uniformly
                    setBalanceData({
                        data: normalizeDailyData(data.data),
                        account: data.account,
                    });
                } else {
                    const from = format(subMonths(now, 12), 'yyyy-MM-dd');
                    const params = new URLSearchParams({ from, to });
                    const response = await fetch(
                        `/api/dashboard/account/${account.id}/balance-evolution?${params.toString()}`,
                    );
                    const data = await response.json();
                    setBalanceData(data);
                }
            } catch (error) {
                console.error('Failed to fetch balance data:', error);
            } finally {
                setIsLoading(false);
            }
        },
        [account.id],
    );

    useEffect(() => {
        fetchBalanceData(granularity);
    }, [fetchBalanceData, granularity, refreshKey]);

    const { chartData, currentBalance, shortTrend, longTrend } = useMemo(() => {
        if (!balanceData?.data?.length) {
            return {
                chartData: [],
                currentBalance: 0,
                shortTrend: null,
                longTrend: null,
            };
        }

        const data = balanceData.data;
        const current = data[data.length - 1]?.value ?? 0;

        return {
            chartData: data,
            currentBalance: current,
            shortTrend: calculateTrend(data, 1),
            longTrend: calculateTrend(data, data.length - 1),
        };
    }, [balanceData]);

    // Convert data for useChartViews hook
    const { data: hookData, accounts: hookAccounts } = useMemo(() => {
        return convertSingleAccountData(
            chartData,
            account.id,
            account.type,
            account.currency_code,
        );
    }, [chartData, account.id, account.type, account.currency_code]);

    const chartViews = useChartViews({
        data: hookData,
        accounts: hookAccounts,
        initialView: 'stacked',
        hasStackedView: true,
    });

    const chartConfig: ChartConfig = {
        value: {
            label: (
                <AccountName account={account} length={{ min: 5, max: 20 }} />
            ),

            color: 'var(--color-chart-2)',
        },
    };

    const formatXAxisLabel = useMemo(
        () => createXAxisFormatter(locale, granularity),
        [locale, granularity],
    );

    const valueFormatter = (value: number): string => {
        return formatChartCurrency(value, account.currency_code, locale);
    };

    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const minBarWidth = granularity === 'daily' ? 20 : 50;
    const minChartWidth = chartData.length * minBarWidth;

    useEffect(() => {
        if (scrollContainerRef.current) {
            scrollContainerRef.current.scrollLeft =
                scrollContainerRef.current.scrollWidth;
        }
    }, [chartData]);

    const shortTrendLabel =
        granularity === 'daily' ? __('today') : __('this month');
    const longTrendLabel =
        granularity === 'daily'
            ? __('for the last 30 days')
            : __('for the last 12 months');

    if (initialLoading || isLoading) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__('Balance evolution')}</CardTitle>
                    <CardDescription>
                        <div className="h-4 w-48 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="h-[300px] w-full animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    if (chartData.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__('Balance evolution')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        {__('No balance data available')}
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="group">
            <CardHeader>
                <div className="flex flex-row items-start justify-between">
                    <div className="flex flex-col gap-1 sm:gap-2">
                        <CardTitle>{__('Balance evolution')}</CardTitle>
                        <button
                            type="button"
                            onClick={onBalanceClick}
                            className="-ml-3 cursor-pointer rounded-md px-2 py-1 text-left text-4xl font-semibold tabular-nums transition-colors hover:bg-muted"
                        >
                            <AmountDisplay
                                amountInCents={currentBalance}
                                currencyCode={account.currency_code}
                                minimumFractionDigits={0}
                                maximumFractionDigits={0}
                            />
                        </button>
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <PercentageTrendIndicator
                                trend={shortTrend?.percentage ?? null}
                                label={shortTrendLabel}
                                previousAmount={shortTrend?.previousValue}
                                currentAmount={shortTrend?.currentValue}
                                currencyCode={account.currency_code}
                            />

                            <PercentageTrendIndicator
                                trend={longTrend?.percentage ?? null}
                                label={longTrendLabel}
                                previousAmount={longTrend?.previousValue}
                                currentAmount={longTrend?.currentValue}
                                currencyCode={account.currency_code}
                            />
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        <ChartGranularityToggle
                            value={granularity}
                            onValueChange={setGranularity}
                        />
                        <ChartViewToggle
                            value={chartViews.currentView}
                            onValueChange={chartViews.setCurrentView}
                            availableViews={chartViews.availableViews}
                            granularity={granularity}
                        />
                    </div>
                </div>
            </CardHeader>
            <CardContent className="relative">
                {chartViews.currentView === 'stacked' && (
                    <div
                        ref={scrollContainerRef}
                        className="h-[300px] w-full overflow-x-auto"
                    >
                        <ChartContainer
                            config={chartConfig}
                            className="h-full w-full"
                            style={{ minWidth: `${minChartWidth}px` }}
                        >
                            {granularity === 'daily' ? (
                                <AreaChart
                                    accessibilityLayer
                                    data={chartData.slice(1)}
                                >
                                    <defs>
                                        <linearGradient
                                            id="fillBalance"
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="1"
                                        >
                                            <stop
                                                offset="5%"
                                                stopColor="var(--color-chart-2)"
                                                stopOpacity={0.3}
                                            />
                                            <stop
                                                offset="95%"
                                                stopColor="var(--color-chart-2)"
                                                stopOpacity={0.05}
                                            />
                                        </linearGradient>
                                    </defs>
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent
                                                hideLabel
                                                valueFormatter={valueFormatter}
                                            />
                                        }
                                    />
                                    <Area
                                        dataKey="value"
                                        type="monotone"
                                        fill="url(#fillBalance)"
                                        stroke="var(--color-chart-2)"
                                        strokeWidth={2}
                                        dot={false}
                                        activeDot={{ r: 5 }}
                                        fillOpacity={1}
                                    />
                                </AreaChart>
                            ) : (
                                <BarChart
                                    accessibilityLayer
                                    data={chartData.slice(1)}
                                >
                                    <XAxis
                                        dataKey="month"
                                        tickLine={false}
                                        tickMargin={10}
                                        axisLine={false}
                                        tickFormatter={formatXAxisLabel}
                                    />
                                    <ChartTooltip
                                        content={
                                            <ChartTooltipContent
                                                hideLabel
                                                valueFormatter={valueFormatter}
                                            />
                                        }
                                    />
                                    <Bar
                                        dataKey="value"
                                        fill="var(--color-chart-2)"
                                        radius={[4, 4, 0, 0]}
                                    />
                                </BarChart>
                            )}
                        </ChartContainer>
                    </div>
                )}
                {chartViews.currentView === 'mom' && (
                    <MoMChart
                        data={chartViews.deltaSeries}
                        currencyCode={account.currency_code}
                        xAxisFormatter={formatXAxisLabel}
                        className="h-[300px] w-full"
                    />
                )}
                {chartViews.currentView === 'mom_percent' && (
                    <MoMPercentChart
                        data={chartViews.momPercentSeries}
                        xAxisFormatter={formatXAxisLabel}
                        className="h-[300px] w-full"
                    />
                )}
            </CardContent>
        </Card>
    );
}
