import { EncryptedText } from '@/components/encrypted-text';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig } from '@/components/ui/chart';
import { StackedBarChart } from '@/components/ui/stacked-bar-chart';
import { NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { useMemo } from 'react';
import { PercentageTrendIndicator } from './percentage-trend-indicator';

interface NetWorthChartProps {
    data: NetWorthEvolutionData;
    loading?: boolean;
    showLegend?: boolean;
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

function formatCurrencyWithCode(value: number, currencyCode: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value / 100);
}

function calculateTrend(
    data: Array<Record<string, string | number>>,
    accountIds: string[],
    monthsBack: number,
): number | null {
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

    return ((currentTotal - previousTotal) / Math.abs(previousTotal)) * 100;
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
            <span className="text-4xl font-semibold tabular-nums">
                {formatCurrencyWithCode(totals[0].total, totals[0].currency)}
            </span>
        );
    }

    return (
        <div className="flex items-baseline gap-1">
            {totals.map((item, index) => (
                <span key={item.currency} className="flex items-baseline">
                    {index > 0 && (
                        <span className="mx-1 text-2xl opacity-50">+</span>
                    )}
                    <span className="text-4xl font-semibold tabular-nums">
                        {formatCurrencyWithCode(item.total, item.currency)}
                    </span>
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
    } = useMemo(() => {
        const accounts = data.accounts || {};
        const accountIds = Object.keys(accounts);
        const chartDataArray = data.data || [];

        const config: ChartConfig = {};
        const currencies: Record<string, string> = {};

        accountIds.forEach((id) => {
            const account = accounts[id];
            config[id] = {
                label: account ? <EncryptedLabel account={account} /> : id,
            };
            if (account?.currency_code) {
                currencies[id] = account.currency_code;
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
        };
    }, [data]);

    const valueFormatter = useMemo(() => {
        return (value: number, accountId?: string): string => {
            const currency =
                accountId && accountCurrencies[accountId]
                    ? accountCurrencies[accountId]
                    : 'USD';
            return formatCurrencyWithCode(value, currency);
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
        <Card className="col-span-3">
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="flex flex-col gap-2">
                        <div className="flex items-start justify-between">
                            <CardTitle>Net Worth Evolution</CardTitle>
                        </div>
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <PercentageTrendIndicator
                                trend={monthlyTrend}
                                label="this month"
                            />
                            <PercentageTrendIndicator
                                trend={yearlyTrend}
                                label="for the last 12 months"
                            />
                        </CardDescription>
                    </div>

                    <div>
                        <TotalDisplay totals={currencyTotals} />
                    </div>
                </div>
            </CardHeader>
            <CardContent>
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
            </CardContent>
        </Card>
    );
}
