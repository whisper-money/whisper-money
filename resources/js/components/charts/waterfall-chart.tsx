import { AmountDisplay } from '@/components/ui/amount-display';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { MonthDataPoint, WaterfallDataPoint } from '@/lib/chart-calculations';
import { cn } from '@/lib/utils';
import { AlertCircle } from 'lucide-react';
import { Bar, BarChart, Cell, Tooltip, XAxis, YAxis } from 'recharts';

interface WaterfallChartProps {
    data: WaterfallDataPoint[];
    monthlyData: MonthDataPoint[];
    selectedMonthIndex: number;
    onMonthIndexChange: (index: number) => void;
    currencyCode: string;
    className?: string;
}

const chartConfig: ChartConfig = {
    value: {
        label: 'Value',
        color: 'var(--color-chart-1)',
    },
};

function getBarColor(point: WaterfallDataPoint): string {
    if (point.type === 'start' || point.type === 'end') {
        return 'var(--color-chart-1)';
    }
    // Change bar
    return point.value >= 0 ? 'var(--color-chart-2)' : 'var(--color-chart-5)';
}

interface TooltipPayloadItem {
    payload?: WaterfallDataPoint;
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: TooltipPayloadItem[];
    currencyCode: string;
}

function CustomTooltip({ active, payload, currencyCode }: CustomTooltipProps) {
    if (!active || !payload?.length) return null;

    const data = payload[0].payload;
    if (!data) return null;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{data.name}</div>
            {data.month && (
                <div className="text-muted-foreground">{data.month}</div>
            )}
            <div className="mt-1 flex items-center justify-between gap-4">
                <span className="text-muted-foreground">
                    {data.type === 'change' ? 'Change' : 'Balance'}
                </span>
                <span
                    className={cn(
                        'font-mono font-medium tabular-nums',
                        data.type === 'change' &&
                            data.value > 0 &&
                            'text-emerald-600',
                        data.type === 'change' &&
                            data.value < 0 &&
                            'text-red-600',
                    )}
                >
                    <AmountDisplay
                        amountInCents={data.value}
                        currencyCode={currencyCode}
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                    />
                </span>
            </div>
        </div>
    );
}

function formatXAxisLabel(value: string): string {
    const [year, month] = value.split('-');
    if (!year || !month) return value;
    const date = new Date(parseInt(year), parseInt(month) - 1);
    const monthName = date.toLocaleString('en-US', { month: 'short' });
    const currentYear = new Date().getFullYear();

    if (parseInt(year) === currentYear) {
        return monthName;
    }

    return `${monthName} ${year.slice(-2)}`;
}

export function WaterfallChart({
    data,
    monthlyData,
    selectedMonthIndex,
    onMonthIndexChange,
    currencyCode,
    className,
}: WaterfallChartProps) {
    // Get available months (need at least 2 to show a transition)
    const availableMonths = monthlyData.slice(1).map((point, i) => ({
        index: i + 1,
        label: formatXAxisLabel(point.month),
        month: point.month,
    }));

    const effectiveIndex =
        selectedMonthIndex === -1 ? monthlyData.length - 1 : selectedMonthIndex;

    if (data.length === 0) {
        return (
            <div className="flex h-[300px] flex-col items-center justify-center gap-2 text-muted-foreground">
                <AlertCircle className="size-8" />
                <p className="text-sm">
                    Not enough data to show waterfall chart
                </p>
                <p className="text-xs">Need at least 2 months of data</p>
            </div>
        );
    }

    // Transform data for stacked waterfall visualization
    // We need to show bars where start and end are full height,
    // but change bar starts from the previous value
    const startValue = data.find((d) => d.type === 'start')?.value ?? 0;
    const changeValue = data.find((d) => d.type === 'change')?.value ?? 0;

    const waterfallData = data.map((point) => {
        if (point.type === 'start') {
            return {
                ...point,
                displayValue: point.value,
                baseValue: 0,
            };
        }
        if (point.type === 'change') {
            // For change, we need to position it relative to start
            if (changeValue >= 0) {
                return {
                    ...point,
                    displayValue: changeValue,
                    baseValue: startValue,
                };
            } else {
                // Negative change starts from new end position
                return {
                    ...point,
                    displayValue: Math.abs(changeValue),
                    baseValue: startValue + changeValue,
                };
            }
        }
        // End
        return {
            ...point,
            displayValue: point.value,
            baseValue: 0,
        };
    });

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
                <div className="text-xs text-muted-foreground">
                    No flow data available; showing net change only
                </div>
                <Select
                    value={effectiveIndex.toString()}
                    onValueChange={(v) => onMonthIndexChange(parseInt(v))}
                >
                    <SelectTrigger className="h-8 w-[140px]">
                        <SelectValue placeholder="Select month" />
                    </SelectTrigger>
                    <SelectContent>
                        {availableMonths.map((m) => (
                            <SelectItem
                                key={m.index}
                                value={m.index.toString()}
                            >
                                {m.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <ChartContainer config={chartConfig} className={className}>
                <BarChart accessibilityLayer data={waterfallData}>
                    <XAxis
                        dataKey="name"
                        tickLine={false}
                        tickMargin={10}
                        axisLine={false}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        tickFormatter={(value: number) =>
                            new Intl.NumberFormat('en-US', {
                                notation: 'compact',
                                compactDisplay: 'short',
                            }).format(value / 100)
                        }
                        width={50}
                    />
                    <Tooltip
                        content={<CustomTooltip currencyCode={currencyCode} />}
                        cursor={{ fill: 'var(--color-muted)', opacity: 0.3 }}
                    />
                    {/* Hidden base bar to position the visible bar */}
                    <Bar
                        dataKey="baseValue"
                        stackId="stack"
                        fill="transparent"
                    />
                    <Bar
                        dataKey="displayValue"
                        stackId="stack"
                        radius={[4, 4, 0, 0]}
                    >
                        {waterfallData.map((entry, index) => (
                            <Cell
                                key={`cell-${index}`}
                                fill={getBarColor(entry)}
                            />
                        ))}
                    </Bar>
                </BarChart>
            </ChartContainer>
        </div>
    );
}
