import { AccountName } from '@/components/accounts/account-name';
import {
    type ChartGranularity,
    ChartGranularityToggle,
    ChartSettingsPopover,
    ChartViewToggle,
    MoMChart,
    MoMPercentChart,
} from '@/components/charts';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig } from '@/components/ui/chart';
import { StackedAreaChart } from '@/components/ui/stacked-area-chart';
import { StackedBarChart } from '@/components/ui/stacked-bar-chart';
import { useChartViews } from '@/hooks/use-chart-views';
import {
    NetWorthEvolutionData,
    OriginalAmount,
} from '@/hooks/use-dashboard-data';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { AccountInfo } from '@/lib/chart-calculations';
import { formatDayFromDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { format, subDays } from 'date-fns';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

interface NetWorthChartProps {
    data: NetWorthEvolutionData;
    loading?: boolean;
    showLegend?: boolean;
}

const DAILY_DAYS = 30;

interface TrendData {
    percentage: number;
    previousAmount: number;
    currentAmount: number;
}

function formatXAxisLabel(
    value: string,
    locale: string = 'en',
    granularity: ChartGranularity = 'monthly',
): string {
    if (granularity === 'daily') {
        return formatDayFromDate(value, locale);
    }

    const [year, month] = value.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const monthName = date.toLocaleString(locale, { month: 'short' });
    const currentYear = new Date().getFullYear();

    if (parseInt(year) === currentYear) {
        return monthName;
    }

    return `${monthName} ${year.slice(-2)}`;
}

function calculateTrend(
    data: Array<Record<string, string | number | OriginalAmount>>,
    accountIds: string[],
    periodsBack: number,
): TrendData | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - periodsBack);

    if (currentIndex === previousIndex) return null;

    const currentTotal = accountIds.reduce((sum, id) => {
        const value = data[currentIndex][id];
        return sum + (typeof value === 'number' ? value : 0);
    }, 0);

    const previousTotal = accountIds.reduce((sum, id) => {
        const value = data[previousIndex][id];
        return sum + (typeof value === 'number' ? value : 0);
    }, 0);

    if (previousTotal === 0) return null;

    return {
        percentage:
            ((currentTotal - previousTotal) / Math.abs(previousTotal)) * 100,
        previousAmount: previousTotal,
        currentAmount: currentTotal,
    };
}

interface EncryptedLabelProps {
    account: { name: string; name_iv: string | null; encrypted: boolean };
}

function EncryptedLabel({ account }: EncryptedLabelProps) {
    return <AccountName account={account} length={{ min: 5, max: 20 }} />;
}

export function NetWorthChart({
    data: monthlyData,
    loading,
    showLegend = false,
}: NetWorthChartProps) {
    const locale = useLocale();
    const isMobile = useIsMobile();
    const [granularity, setGranularity] = useState<ChartGranularity>('monthly');
    const [dailyData, setDailyData] = useState<NetWorthEvolutionData | null>(
        null,
    );
    const [isDailyLoading, setIsDailyLoading] = useState(false);

    const fetchDailyData = useCallback(async () => {
        setIsDailyLoading(true);
        try {
            const now = new Date();
            const to = format(now, 'yyyy-MM-dd');
            const from = format(subDays(now, DAILY_DAYS), 'yyyy-MM-dd');
            const params = new URLSearchParams({ from, to });
            const response = await fetch(
                `/api/dashboard/net-worth-daily-evolution?${params.toString()}`,
            );
            const data: NetWorthEvolutionData = await response.json();

            // Normalize daily data: rename "date" key to "month" so it works
            // uniformly with useChartViews and the rest of the component.
            const normalizedData = data.data.map((point) => {
                const { date, ...rest } = point as Record<string, unknown> & {
                    date: string;
                };
                return { ...rest, month: date };
            }) as Array<Record<string, string | number | OriginalAmount>>;

            setDailyData({
                data: normalizedData,
                accounts: data.accounts,
                currency_code: data.currency_code,
            });
        } catch (error) {
            console.error('Failed to fetch daily net worth data:', error);
        } finally {
            setIsDailyLoading(false);
        }
    }, []);

    useEffect(() => {
        if (granularity === 'daily' && !dailyData) {
            fetchDailyData();
        }
    }, [granularity, dailyData, fetchDailyData]);

    const activeData =
        granularity === 'daily' && dailyData ? dailyData : monthlyData;

    const userCurrency = activeData.currency_code || 'USD';

    const {
        chartData,
        dataKeys,
        chartConfig,
        shortTrend,
        longTrend,
        totalAmount,
        accountCurrencies,
        accountsForHook,
    } = useMemo(() => {
        const accounts = activeData.accounts || {};
        const accountIds = Object.keys(accounts);
        const chartDataArray = activeData.data || [];

        const config: ChartConfig = {};
        const currencies: Record<string, string> = {};
        const hookAccounts: Record<string, AccountInfo> = {};

        accountIds.forEach((id) => {
            const account = accounts[id];
            config[id] = {
                label: account ? <EncryptedLabel account={account} /> : id,
            };
            if (account?.currency_code) {
                currencies[id] = account.currency_code;
            }
            if (account) {
                hookAccounts[id] = {
                    id: account.id,
                    type: account.type,
                    currency_code: account.currency_code,
                };
            }
        });

        // All values are now in the user's currency, so compute a single total
        let total = 0;
        if (chartDataArray.length > 0) {
            const lastDataPoint = chartDataArray[chartDataArray.length - 1];
            accountIds.forEach((id) => {
                const value = lastDataPoint[id];
                if (typeof value === 'number') {
                    total += value;
                }
            });
        }

        return {
            chartData: chartDataArray,
            dataKeys: accountIds,
            chartConfig: config,
            shortTrend: calculateTrend(chartDataArray, accountIds, 1),
            longTrend: calculateTrend(
                chartDataArray,
                accountIds,
                chartDataArray.length - 1,
            ),
            totalAmount: total,
            accountCurrencies: currencies,
            accountsForHook: hookAccounts,
        };
    }, [activeData]);

    const chartViews = useChartViews({
        data: chartData as Array<Record<string, string | number>>,
        accounts: accountsForHook,
        initialView: 'stacked',
        hasStackedView: true,
    });

    const valueFormatter = useMemo(() => {
        return (value: number): React.ReactNode => {
            return (
                <AmountDisplay
                    amountInCents={value}
                    currencyCode={userCurrency}
                    minimumFractionDigits={0}
                    maximumFractionDigits={0}
                />
            );
        };
    }, [userCurrency]);

    const shortTrendLabel =
        granularity === 'daily' ? __('today') : __('this month');
    const longTrendLabel =
        granularity === 'daily'
            ? __('for the last 30 days')
            : __('for the last 12 months');

    if (loading || (granularity === 'daily' && isDailyLoading)) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>{__('Net Worth Evolution')}</CardTitle>
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

    if (dataKeys.length === 0) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>{__('Net Worth Evolution')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        {__('No account data available')}
                    </div>
                </CardContent>
            </Card>
        );
    }

    const xAxisFormatter = (value: string) =>
        formatXAxisLabel(value, locale, granularity);

    return (
        <Card className="group">
            <CardHeader>
                <div className="flex flex-row items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-2">
                        <CardTitle>{__('Net Worth Evolution')}</CardTitle>
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <div className="text-foreground">
                                <AmountDisplay
                                    amountInCents={totalAmount}
                                    currencyCode={userCurrency}
                                    variant="large"
                                    minimumFractionDigits={0}
                                    maximumFractionDigits={0}
                                />
                            </div>
                            <PercentageTrendIndicator
                                trend={shortTrend?.percentage ?? null}
                                label={shortTrendLabel}
                                previousAmount={shortTrend?.previousAmount}
                                currentAmount={shortTrend?.currentAmount}
                                currencyCode={userCurrency}
                            />

                            <PercentageTrendIndicator
                                trend={longTrend?.percentage ?? null}
                                label={longTrendLabel}
                                previousAmount={longTrend?.previousAmount}
                                currentAmount={longTrend?.currentAmount}
                                currencyCode={userCurrency}
                            />
                        </CardDescription>
                    </div>

                    <div className="flex items-center gap-2">
                        {isMobile ? (
                            <ChartSettingsPopover
                                granularity={granularity}
                                onGranularityChange={setGranularity}
                                currentView={chartViews.currentView}
                                onViewChange={chartViews.setCurrentView}
                                availableViews={chartViews.availableViews}
                            />
                        ) : (
                            <>
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
                            </>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="relative min-w-0 overflow-hidden">
                {chartViews.currentView === 'stacked' &&
                    (granularity === 'daily' ? (
                        <StackedAreaChart
                            data={chartData.slice(1)}
                            dataKeys={dataKeys}
                            config={chartConfig}
                            xAxisKey="month"
                            xAxisFormatter={xAxisFormatter}
                            valueFormatter={valueFormatter}
                            accountCurrencies={accountCurrencies}
                            displayCurrency={userCurrency}
                            className="h-[300px] w-full"
                            showLegend={showLegend}
                        />
                    ) : (
                        <StackedBarChart
                            data={chartData.slice(1)}
                            dataKeys={dataKeys}
                            config={chartConfig}
                            xAxisKey="month"
                            xAxisFormatter={xAxisFormatter}
                            valueFormatter={valueFormatter}
                            accountCurrencies={accountCurrencies}
                            displayCurrency={userCurrency}
                            className="h-[300px] w-full"
                            showLegend={showLegend}
                        />
                    ))}
                {chartViews.currentView === 'mom' && (
                    <MoMChart
                        data={chartViews.deltaSeries}
                        currencyCode={userCurrency}
                        xAxisFormatter={xAxisFormatter}
                        className="h-[300px] w-full"
                    />
                )}
                {chartViews.currentView === 'mom_percent' && (
                    <MoMPercentChart
                        data={chartViews.momPercentSeries}
                        xAxisFormatter={xAxisFormatter}
                        className="h-[300px] w-full"
                    />
                )}
            </CardContent>
        </Card>
    );
}
