import { EncryptedText } from '@/components/encrypted-text';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig } from '@/components/ui/chart';
import {
    ColorPalette,
    StackedBarChart,
} from '@/components/ui/stacked-bar-chart';
import { NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { useMemo } from 'react';

interface NetWorthChartProps {
    data: NetWorthEvolutionData;
    loading?: boolean;
    color?: ColorPalette;
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

function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
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

function TrendIndicator({
    trend,
    label,
}: {
    trend: number | null;
    label: string;
}) {
    if (trend === null) return null;

    const isPositive = trend >= 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;
    const iconColorClass = isPositive
        ? 'text-green-600/70 dark:text-green-400/70'
        : 'text-red-600/70 dark:text-red-400/70';

    return (
        <div className="flex items-center gap-1">
            <span
                className={
                    isPositive ? 'bg-green-100/25 dark:bg-green-900/25' : ''
                }
            >
                {isPositive ? '+' : ''}
                {trend.toFixed(1)}%
            </span>
            <span className="text-muted-foreground">{label}</span>
            <Icon className={`h-4 w-4 ${iconColorClass}`} />
        </div>
    );
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

export function NetWorthChart({
    data,
    loading,
    color = 'zinc',
    showLegend = false,
}: NetWorthChartProps) {
    const {
        chartData,
        dataKeys,
        chartConfig,
        monthlyTrend,
        yearlyTrend,
        currentNetWorth,
    } = useMemo(() => {
        const accounts = data.accounts || {};
        const accountIds = Object.keys(accounts);
        const chartDataArray = data.data || [];

        const config: ChartConfig = {};
        accountIds.forEach((id) => {
            const account = accounts[id];
            config[id] = {
                label: account ? <EncryptedLabel account={account} /> : id,
            };
        });

        const currentTotal =
            chartDataArray.length > 0
                ? accountIds.reduce((sum, id) => {
                    const value =
                        chartDataArray[chartDataArray.length - 1][id];
                    return sum + (typeof value === 'number' ? value : 0);
                }, 0)
                : 0;

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
            currentNetWorth: currentTotal,
        };
    }, [data]);

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
                            <TrendIndicator trend={monthlyTrend} label="this month" />
                            <TrendIndicator
                                trend={yearlyTrend}
                                label="for the last 12 months"
                            />
                        </CardDescription>
                    </div>

                    <div>
                        <span className="text-4xl font-semibold tabular-nums">
                            {formatCurrency(currentNetWorth)}
                        </span>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <StackedBarChart
                    data={chartData}
                    dataKeys={dataKeys}
                    config={chartConfig}
                    color={color}
                    xAxisKey="month"
                    xAxisFormatter={formatXAxisLabel}
                    valueFormatter={formatCurrency}
                    className="h-[300px] w-full"
                    showLegend={showLegend}
                />
            </CardContent>
        </Card>
    );
}
