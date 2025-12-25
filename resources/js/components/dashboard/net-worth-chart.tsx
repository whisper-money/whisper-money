import {
    ChangePercentChart,
    ChartViewToggle,
    NetWorthLineChart,
    WaterfallChart,
} from '@/components/charts';
import { EncryptedText } from '@/components/encrypted-text';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig } from '@/components/ui/chart';
import { StackedBarChart } from '@/components/ui/stacked-bar-chart';
import { useChartViews } from '@/hooks/use-chart-views';
import { NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { AccountInfo } from '@/lib/chart-calculations';
import { useMemo } from 'react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

interface NetWorthChartProps {
    data: NetWorthEvolutionData;
    loading?: boolean;
    showLegend?: boolean;
}

interface TrendData {
    percentage: number;
    previousAmount: number;
    currentAmount: number;
}

function formatXAxisLabel(value: string): string {
    const [year, month] = value.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const monthName = date.toLocaleString('en-US', { month: 'short' });
    const currentYear = new Date().getFullYear();

    if (parseInt(year) === currentYear) {
        return monthName;
    }

    return `${monthName} ${year.slice(-2)}`;
}

function calculateTrend(
    data: Array<Record<string, string | number>>,
    accountIds: string[],
    monthsBack: number,
): TrendData | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - monthsBack);

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
    account: { name: string; name_iv: string };
}

function EncryptedLabel({ account }: EncryptedLabelProps) {
    return (
        <EncryptedText
            encryptedText={account.name}
            iv={account.name_iv}
            length={{ min: 5, max: 20 }}
        />
    );
}

interface CurrencyTotal {
    currency: string;
    total: number;
}

function TotalDisplay({ totals }: { totals: CurrencyTotal[] }) {
    if (totals.length === 0) return null;

    if (totals.length === 1) {
        return (
            <AmountDisplay
                amountInCents={totals[0].total}
                currencyCode={totals[0].currency}
                variant="large"
                minimumFractionDigits={0}
                maximumFractionDigits={0}
            />
        );
    }

    return (
        <div className="flex flex-wrap items-baseline justify-end gap-1">
            {totals.map((item, index) => (
                <span key={item.currency} className="flex items-baseline">
                    {index > 0 && (
                        <span className="mx-1 text-lg opacity-50 sm:text-2xl">
                            +
                        </span>
                    )}
                    <AmountDisplay
                        amountInCents={item.total}
                        currencyCode={item.currency}
                        variant="large"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                    />
                </span>
            ))}
        </div>
    );
}

export function NetWorthChart({
    data,
    loading,
    showLegend = false,
}: NetWorthChartProps) {
    const {
        chartData,
        dataKeys,
        chartConfig,
        monthlyTrend,
        yearlyTrend,
        currencyTotals,
        accountCurrencies,
        primaryCurrency,
        accountsForHook,
    } = useMemo(() => {
        const accounts = data.accounts || {};
        const accountIds = Object.keys(accounts);
        const chartDataArray = data.data || [];

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

        const totals: Record<string, number> = {};
        if (chartDataArray.length > 0) {
            const lastDataPoint = chartDataArray[chartDataArray.length - 1];
            accountIds.forEach((id) => {
                const value = lastDataPoint[id];
                const currency = currencies[id] || 'USD';
                if (typeof value === 'number') {
                    totals[currency] = (totals[currency] || 0) + value;
                }
            });
        }

        const currencyTotalsList: CurrencyTotal[] = Object.entries(totals)
            .map(([currency, total]) => ({ currency, total }))
            .sort((a, b) => b.total - a.total);

        const primary =
            currencyTotalsList.length > 0
                ? currencyTotalsList[0].currency
                : 'USD';

        return {
            chartData: chartDataArray,
            dataKeys: accountIds,
            chartConfig: config,
            monthlyTrend: calculateTrend(chartDataArray, accountIds, 1),
            yearlyTrend: calculateTrend(
                chartDataArray,
                accountIds,
                chartDataArray.length - 1,
            ),
            currencyTotals: currencyTotalsList,
            accountCurrencies: currencies,
            primaryCurrency: primary,
            accountsForHook: hookAccounts,
        };
    }, [data]);

    const chartViews = useChartViews({
        data: chartData,
        accounts: accountsForHook,
        initialView: 'stacked',
        hasStackedView: true,
    });

    const valueFormatter = useMemo(() => {
        return (value: number, accountId?: string): React.ReactNode => {
            const currency =
                accountId && accountCurrencies[accountId]
                    ? accountCurrencies[accountId]
                    : 'USD';
            return (
                <AmountDisplay
                    amountInCents={value}
                    currencyCode={currency}
                    minimumFractionDigits={0}
                    maximumFractionDigits={0}
                />
            );
        };
    }, [accountCurrencies]);

    if (loading) {
        return (
            <Card className="col-span-3">
                <CardHeader>
                    <CardTitle>Net Worth Evolution</CardTitle>
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
                    <CardTitle>Net Worth Evolution</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        No account data available
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className="overflow-hidden">
            <CardHeader>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 flex-col gap-2">
                        <div className="flex items-center gap-3">
                            <CardTitle>Net Worth Evolution</CardTitle>
                            <ChartViewToggle
                                value={chartViews.currentView}
                                onValueChange={chartViews.setCurrentView}
                                availableViews={chartViews.availableViews}
                            />
                        </div>
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <PercentageTrendIndicator
                                trend={monthlyTrend?.percentage ?? null}
                                label="this month"
                                previousAmount={monthlyTrend?.previousAmount}
                                currentAmount={monthlyTrend?.currentAmount}
                                currencyCode={primaryCurrency}
                            />
                            <PercentageTrendIndicator
                                trend={yearlyTrend?.percentage ?? null}
                                label="for the last 12 months"
                                previousAmount={yearlyTrend?.previousAmount}
                                currentAmount={yearlyTrend?.currentAmount}
                                currencyCode={primaryCurrency}
                            />
                        </CardDescription>
                    </div>

                    <div className="shrink-0">
                        <TotalDisplay totals={currencyTotals} />
                    </div>
                </div>
            </CardHeader>
            <CardContent className="min-w-0">
                {chartViews.currentView === 'stacked' && (
                    <StackedBarChart
                        data={chartData}
                        dataKeys={dataKeys}
                        config={chartConfig}
                        xAxisKey="month"
                        xAxisFormatter={formatXAxisLabel}
                        valueFormatter={valueFormatter}
                        accountCurrencies={accountCurrencies}
                        className="h-[300px] w-full"
                        showLegend={showLegend}
                    />
                )}
                {chartViews.currentView === 'line' && (
                    <NetWorthLineChart
                        data={chartViews.netWorthSeries}
                        currencyCode={primaryCurrency}
                        scaleType={chartViews.scaleType}
                        onScaleTypeChange={chartViews.setScaleType}
                        canUseLog={chartViews.canUseLog}
                        logScaleWarning={chartViews.logScaleWarning}
                        xAxisFormatter={formatXAxisLabel}
                        className="h-[300px] w-full"
                    />
                )}
                {chartViews.currentView === 'change' && (
                    <ChangePercentChart
                        data={chartViews.currentChangeSeries}
                        seriesType={chartViews.changeSeriesType}
                        onSeriesTypeChange={chartViews.setChangeSeriesType}
                        seriesKey={chartViews.changeSeriesKey}
                        currencyCode={primaryCurrency}
                        xAxisFormatter={formatXAxisLabel}
                        className="h-[300px] w-full"
                    />
                )}
                {chartViews.currentView === 'waterfall' && (
                    <WaterfallChart
                        data={chartViews.waterfallSeries}
                        monthlyData={chartViews.netWorthSeries}
                        selectedMonthIndex={chartViews.waterfallMonthIndex}
                        onMonthIndexChange={chartViews.setWaterfallMonthIndex}
                        currencyCode={primaryCurrency}
                        className="h-[300px] w-full"
                    />
                )}
            </CardContent>
        </Card>
    );
}
