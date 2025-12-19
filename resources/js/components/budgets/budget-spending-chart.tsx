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
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { BudgetPeriod } from '@/types/budget';
import { formatCurrency } from '@/utils/currency';
import { useMemo } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

interface Props {
    currentPeriod: BudgetPeriod;
    budgetName: string;
}

export function BudgetSpendingChart({ currentPeriod, budgetName }: Props) {
    const chartData = useMemo(() => {
        const transactions = currentPeriod.budget_transactions || [];
        const startDate = new Date(currentPeriod.start_date);
        const endDate = new Date(currentPeriod.end_date);

        // Group transactions by date (using the actual transaction date, not when it was assigned)
        const transactionsByDate = new Map<string, number>();
        transactions.forEach((t) => {
            if (!t.transaction) return;
            const date = new Date(t.transaction.transaction_date).toISOString().split('T')[0];
            transactionsByDate.set(
                date,
                (transactionsByDate.get(date) || 0) + t.amount
            );
        });

        // Generate daily data points
        const data = [];
        let cumulativeSpent = 0;
        const currentDate = new Date(startDate);

        while (currentDate <= endDate && currentDate <= new Date()) {
            const dateStr = currentDate.toISOString().split('T')[0];
            const dailySpent = transactionsByDate.get(dateStr) || 0;
            cumulativeSpent += dailySpent;

            data.push({
                date: dateStr,
                spent: cumulativeSpent,
                allocated: currentPeriod.allocated_amount,
                remaining: currentPeriod.allocated_amount - cumulativeSpent,
            });

            currentDate.setDate(currentDate.getDate() + 1);
        }

        return data;
    }, [currentPeriod]);

    const chartConfig = {
        spent: {
            label: 'Spent',
            color: 'hsl(var(--chart-1))',
        },
        allocated: {
            label: 'Budget',
            color: 'hsl(var(--chart-2))',
        },
    } satisfies ChartConfig;

    const periodLabel = useMemo(() => {
        const start = new Date(currentPeriod.start_date).toLocaleDateString(
            'en-US',
            { month: 'short', day: 'numeric' }
        );
        const end = new Date(currentPeriod.end_date).toLocaleDateString(
            'en-US',
            { month: 'short', day: 'numeric', year: 'numeric' }
        );
        return `${start} - ${end}`;
    }, [currentPeriod]);

    const totalSpent = useMemo(() => {
        return (
            currentPeriod.budget_transactions?.reduce(
                (sum, t) => sum + t.amount,
                0
            ) || 0
        );
    }, [currentPeriod]);

    const percentageUsed = useMemo(() => {
        return currentPeriod.allocated_amount > 0
            ? (totalSpent / currentPeriod.allocated_amount) * 100
            : 0;
    }, [totalSpent, currentPeriod.allocated_amount]);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Budget Spending</CardTitle>
                <CardDescription>
                    Tracking spending for {budgetName} · {periodLabel}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ChartContainer config={chartConfig} className="h-[300px] w-full">
                    <AreaChart
                        data={chartData}
                        margin={{
                            left: 12,
                            right: 12,
                            top: 12,
                        }}
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
                                    offset="5%"
                                    stopColor="var(--color-spent)"
                                    stopOpacity={0.8}
                                />
                                <stop
                                    offset="95%"
                                    stopColor="var(--color-spent)"
                                    stopOpacity={0.1}
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
                                    offset="5%"
                                    stopColor="var(--color-allocated)"
                                    stopOpacity={0.3}
                                />
                                <stop
                                    offset="95%"
                                    stopColor="var(--color-allocated)"
                                    stopOpacity={0.05}
                                />
                            </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            tickFormatter={(value) => {
                                const date = new Date(value);
                                return date.toLocaleDateString('en-US', {
                                    month: 'short',
                                    day: 'numeric',
                                });
                            }}
                        />
                        <YAxis
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            tickFormatter={(value) =>
                                formatCurrency(value).replace('$', '')
                            }
                        />
                        <ChartTooltip
                            content={
                                <ChartTooltipContent
                                    labelFormatter={(value) => {
                                        return new Date(
                                            value as string
                                        ).toLocaleDateString('en-US', {
                                            month: 'short',
                                            day: 'numeric',
                                            year: 'numeric',
                                        });
                                    }}
                                    formatter={(value) =>
                                        formatCurrency(value as number)
                                    }
                                />
                            }
                        />
                        <Area
                            dataKey="allocated"
                            type="monotone"
                            fill="url(#fillAllocated)"
                            stroke="var(--color-allocated)"
                            strokeWidth={2}
                            dot={false}
                        />
                        <Area
                            dataKey="spent"
                            type="monotone"
                            fill="url(#fillSpent)"
                            stroke="var(--color-spent)"
                            strokeWidth={2}
                            dot={false}
                        />
                    </AreaChart>
                </ChartContainer>
                <div className="mt-4 flex items-center justify-between border-t pt-4 text-sm">
                    <div className="flex items-center gap-2">
                        <div className="flex items-center gap-2">
                            <div
                                className="h-3 w-3 rounded-full"
                                style={{
                                    backgroundColor: 'var(--color-spent)',
                                }}
                            />
                            <span className="text-muted-foreground">
                                Spent: {formatCurrency(totalSpent)}
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            <div
                                className="h-3 w-3 rounded-full"
                                style={{
                                    backgroundColor: 'var(--color-allocated)',
                                }}
                            />
                            <span className="text-muted-foreground">
                                Budget: {formatCurrency(currentPeriod.allocated_amount)}
                            </span>
                        </div>
                    </div>
                    <div className="font-medium">
                        {percentageUsed.toFixed(1)}% used
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

