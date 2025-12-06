import { EncryptedText } from '@/components/encrypted-text';
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
import { Account } from '@/types/account';
import { format, subMonths } from 'date-fns';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, XAxis } from 'recharts';

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

interface AccountBalanceChartProps {
    account: Account;
    loading?: boolean;
    refreshKey?: number;
    onBalanceClick?: () => void;
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

function formatCurrency(value: number, currencyCode: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(value / 100);
}

function calculateTrend(
    data: BalanceDataPoint[],
    monthsBack: number,
): number | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - monthsBack);

    if (currentIndex === previousIndex) return null;

    const currentValue = data[currentIndex].value;
    const previousValue = data[previousIndex].value;

    if (previousValue === 0) return null;

    return ((currentValue - previousValue) / Math.abs(previousValue)) * 100;
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

export function AccountBalanceChart({
    account,
    loading: initialLoading,
    refreshKey,
    onBalanceClick,
}: AccountBalanceChartProps) {
    const [balanceData, setBalanceData] = useState<AccountBalanceData | null>(
        null,
    );
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        async function fetchBalanceData() {
            setIsLoading(true);
            try {
                const now = new Date();
                const to = format(now, 'yyyy-MM-dd');
                const from = format(subMonths(now, 12), 'yyyy-MM-dd');

                const params = new URLSearchParams({ from, to });
                const response = await fetch(
                    `/api/dashboard/account/${account.id}/balance-evolution?${params.toString()}`,
                );
                const data = await response.json();
                setBalanceData(data);
            } catch (error) {
                console.error('Failed to fetch balance data:', error);
            } finally {
                setIsLoading(false);
            }
        }

        fetchBalanceData();
    }, [account.id, refreshKey]);

    const { chartData, currentBalance, monthlyTrend, yearlyTrend } =
        useMemo(() => {
            if (!balanceData?.data?.length) {
                return {
                    chartData: [],
                    currentBalance: 0,
                    monthlyTrend: null,
                    yearlyTrend: null,
                };
            }

            const data = balanceData.data;
            const current = data[data.length - 1]?.value ?? 0;

            return {
                chartData: data,
                currentBalance: current,
                monthlyTrend: calculateTrend(data, 1),
                yearlyTrend: calculateTrend(data, data.length - 1),
            };
        }, [balanceData]);

    const chartConfig: ChartConfig = {
        value: {
            label: (
                <EncryptedText
                    encryptedText={account.name}
                    iv={account.name_iv}
                    length={{ min: 5, max: 20 }}
                />
            ),
            color: 'var(--color-chart-2)',
        },
    };

    const valueFormatter = (value: number): string => {
        return formatCurrency(value, account.currency_code);
    };

    if (initialLoading || isLoading) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Balance evolution</CardTitle>
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
                    <CardTitle>Balance evolution</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex h-[300px] items-center justify-center text-muted-foreground">
                        No balance data available
                    </div>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="flex flex-col gap-2">
                        <CardTitle>Balance evolution</CardTitle>
                        <CardDescription className="flex flex-col gap-1 text-sm">
                            <TrendIndicator
                                trend={monthlyTrend}
                                label="this month"
                            />
                            <TrendIndicator
                                trend={yearlyTrend}
                                label="for the last 12 months"
                            />
                        </CardDescription>
                    </div>
                    <div>
                        <button
                            type="button"
                            onClick={onBalanceClick}
                            className="-mr-2 cursor-pointer rounded-md px-2 py-1 text-4xl font-semibold tabular-nums transition-colors hover:bg-muted"
                        >
                            {formatCurrency(
                                currentBalance,
                                account.currency_code,
                            )}
                        </button>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <ChartContainer
                    config={chartConfig}
                    className="h-[300px] w-full"
                >
                    <BarChart accessibilityLayer data={chartData}>
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
                </ChartContainer>
            </CardContent>
        </Card>
    );
}
