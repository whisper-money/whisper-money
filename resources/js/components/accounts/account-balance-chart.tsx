import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
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
import { useEffect, useMemo, useState } from 'react';
import { Bar, BarChart, XAxis } from 'recharts';
import { AmountDisplay } from '../ui/amount-display';

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
): { percentage: number; previousValue: number; currentValue: number } | null {
    if (data.length < 2) return null;

    const currentIndex = data.length - 1;
    const previousIndex = Math.max(0, data.length - 1 - monthsBack);

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
                <div className="flex flex-col items-start justify-between sm:flex-row">
                    <div className="flex flex-col gap-1 sm:gap-2">
                        <CardTitle>Balance evolution</CardTitle>
                        <button
                            type="button"
                            onClick={onBalanceClick}
                            className="-ml-3 cursor-pointer rounded-md px-2 py-1 text-4xl font-semibold tabular-nums transition-colors hover:bg-muted sm:hidden"
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
                                trend={monthlyTrend?.percentage ?? null}
                                label="this month"
                                previousAmount={monthlyTrend?.previousValue}
                                currentAmount={monthlyTrend?.currentValue}
                                currencyCode={account.currency_code}
                            />
                            <PercentageTrendIndicator
                                trend={yearlyTrend?.percentage ?? null}
                                label="for the last 12 months"
                                previousAmount={yearlyTrend?.previousValue}
                                currentAmount={yearlyTrend?.currentValue}
                                currencyCode={account.currency_code}
                            />
                        </CardDescription>
                    </div>
                    <div className="hidden sm:block">
                        <button
                            type="button"
                            onClick={onBalanceClick}
                            className="-mt-1 -mr-2 cursor-pointer rounded-md px-2 py-1 text-4xl font-semibold tabular-nums transition-colors hover:bg-muted"
                        >
                            <AmountDisplay
                                amountInCents={currentBalance}
                                currencyCode={account.currency_code}
                                minimumFractionDigits={0}
                                maximumFractionDigits={0}
                            />
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
