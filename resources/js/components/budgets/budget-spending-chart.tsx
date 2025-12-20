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
import { BudgetPeriod } from '@/types/budget';
import { formatCurrency } from '@/utils/currency';
import { useMemo } from 'react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

interface Props {
    currentPeriod: BudgetPeriod;
    budgetName: string;
    currencyCode: string;
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: Array<{
        payload: {
            date: string;
            spent: number;
            allocated: number;
            remaining: number;
        };
    }>;
    label?: string;
    currencyCode: string;
}

function CustomTooltip({ active, payload, label, currencyCode }: CustomTooltipProps) {
    if (!active || !payload || !payload.length || !label) {
        return null;
    }

    const data = payload[0].payload;
    const allocated = data.allocated;
    const spent = data.spent;
    const available = data.remaining;
    const percentage = allocated > 0 ? Math.round((available / allocated) * 100) : 0;

    return (
        <div className="rounded-lg border bg-background p-3 shadow-lg">
            <p className="mb-2 text-sm font-medium">
                {new Date(label).toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                })}
            </p>
            <div className="space-y-1 text-sm">
                <div className="flex items-center justify-between gap-8">
                    <span className="text-muted-foreground">Allocated:</span>
                    <span className="font-medium">{formatCurrency(allocated, currencyCode)}</span>
                </div>
                <div className="flex items-center justify-between gap-8">
                    <span className="text-muted-foreground">Spent:</span>
                    <span className="font-medium">{formatCurrency(spent, currencyCode)}</span>
                </div>
                <div className="border-t pt-1">
                    <div className="flex items-center justify-between gap-8">
                        <span className="font-medium">Available:</span>
                        <div className="flex items-center gap-2">
                            <span className="font-semibold">{formatCurrency(available, currencyCode)}</span>
                            <span className="text-xs text-muted-foreground">{percentage}%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export function BudgetSpendingChart({ currentPeriod, budgetName, currencyCode }: Props) {
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
                            tickFormatter={(value) => {
                                const formatted = formatCurrency(value, currencyCode);
                                // Remove currency symbol for Y-axis, keep just the number
                                return formatted.replace(/[^\d.,\s-]/g, '').trim();
                            }}
                        />
                        <ChartTooltip
                            content={<CustomTooltip currencyCode={currencyCode} />}
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

