import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import { TrendDataPoint } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { useEffect, useRef } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    ReferenceLine,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface CashflowTrendChartProps {
    data: TrendDataPoint[];
    loading?: boolean;
    className?: string;
}

const chartConfig: ChartConfig = {
    income: {
        label: 'Income',
        color: 'var(--color-chart-2)',
    },
    expense: {
        label: 'Expenses',
        color: 'var(--color-chart-5)',
    },
    net: {
        label: 'Net',
        color: 'var(--color-chart-1)',
    },
};

interface TooltipPayloadItem {
    dataKey?: string;
    value?: number;
    color?: string;
    name?: string;
    payload?: TrendDataPoint;
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: TooltipPayloadItem[];
}

function formatMonth(yearMonth: string): string {
    const [year, month] = yearMonth.split('-');
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const isCurrentYear = date.getFullYear() === new Date().getFullYear();

    return date.toLocaleDateString(
        'en-US',
        isCurrentYear
            ? { month: 'short' }
            : { year: '2-digit', month: 'short' },
    );
}

function CustomTooltip({ active, payload }: CustomTooltipProps) {
    if (!active || !payload?.length) return null;

    const data = payload[0].payload;
    if (!data) return null;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-3 py-2 text-xs shadow-xl">
            <div className="mb-2 font-medium">{formatMonth(data.month)}</div>
            <div className="space-y-1">
                <div className="flex items-center justify-between gap-4">
                    <span className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-[var(--color-chart-2)]" />
                        Income
                    </span>
                    <AmountDisplay
                        amountInCents={data.income}
                        currencyCode="USD"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        className="font-mono font-medium tabular-nums"
                    />
                </div>
                <div className="flex items-center justify-between gap-4">
                    <span className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-[var(--color-chart-5)]" />
                        Expenses
                    </span>
                    <AmountDisplay
                        amountInCents={data.expense}
                        currencyCode="USD"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        className="font-mono font-medium tabular-nums"
                    />
                </div>
                <div className="flex items-center justify-between gap-4 border-t pt-1">
                    <span className="flex items-center gap-2">
                        <span className="size-2 rounded-full bg-[var(--color-chart-1)]" />
                        Net
                    </span>
                    <AmountDisplay
                        amountInCents={data.net}
                        currencyCode="USD"
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                        className={cn(
                            'font-mono font-medium tabular-nums',
                            data.net >= 0 ? 'text-green-600' : 'text-red-600',
                        )}
                    />
                </div>
            </div>
        </div>
    );
}

export function CashflowTrendChart({
    data,
    loading,
    className,
}: CashflowTrendChartProps) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const minChartWidth = data.length * 60;

    useEffect(() => {
        if (scrollContainerRef.current) {
            scrollContainerRef.current.scrollLeft =
                scrollContainerRef.current.scrollWidth;
        }
    }, [data]);

    if (loading) {
        return (
            <Card className={className}>
                <CardHeader>
                    <CardTitle className="text-base">Cashflow Trend</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="h-[250px] animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className={className}>
            <CardHeader className="gap-1 pb-4">
                <CardTitle className="text-base">Cashflow Trend</CardTitle>
                <CardDescription>
                    Monthly income, expenses, and net cashflow over the last 12
                    months
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div ref={scrollContainerRef} className="overflow-x-auto">
                    <ChartContainer
                        config={chartConfig}
                        className="h-[250px] w-full"
                        style={{ minWidth: `${minChartWidth}px` }}
                    >
                        <BarChart accessibilityLayer data={data}>
                            <CartesianGrid
                                strokeDasharray="3 3"
                                vertical={false}
                                stroke="var(--color-border)"
                                opacity={0.3}
                            />
                            <XAxis
                                dataKey="month"
                                tickLine={false}
                                tickMargin={10}
                                axisLine={false}
                                tickFormatter={formatMonth}
                            />
                            <YAxis
                                tickLine={false}
                                axisLine={false}
                                tickFormatter={(value: number) => {
                                    return new Intl.NumberFormat('en-US', {
                                        notation: 'compact',
                                        compactDisplay: 'short',
                                        style: 'currency',
                                        currency: 'USD',
                                        minimumFractionDigits: 0,
                                        maximumFractionDigits: 0,
                                    }).format(value / 100);
                                }}
                                width={60}
                            />
                            <ReferenceLine
                                y={0}
                                stroke="var(--color-border)"
                                strokeDasharray="3 3"
                            />
                            <Tooltip
                                content={<CustomTooltip />}
                                cursor={{
                                    fill: 'var(--color-muted)',
                                    opacity: 0.3,
                                }}
                            />
                            <Bar
                                dataKey="income"
                                fill="var(--color-chart-2)"
                                radius={[4, 4, 0, 0]}
                                stackId="a"
                                name="Income"
                            />
                            <Bar
                                dataKey="expense"
                                fill="var(--color-chart-5)"
                                radius={[4, 4, 0, 0]}
                                stackId="b"
                                name="Expenses"
                            />
                            <Line
                                type="monotone"
                                dataKey="net"
                                stroke="var(--color-chart-1)"
                                strokeWidth={2}
                                dot={{
                                    fill: 'var(--color-chart-1)',
                                    strokeWidth: 0,
                                    r: 3,
                                }}
                                activeDot={{ r: 5 }}
                                name="Net"
                            />
                        </BarChart>
                    </ChartContainer>
                </div>
                <div className="mt-4 flex items-center justify-center gap-6 text-xs">
                    <div className="flex items-center gap-2">
                        <span className="size-3 rounded bg-[var(--color-chart-2)]" />
                        <span>Income</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="size-3 rounded bg-[var(--color-chart-5)]" />
                        <span>Expenses</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="size-3 rounded-full bg-[var(--color-chart-1)]" />
                        <span>Net</span>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
