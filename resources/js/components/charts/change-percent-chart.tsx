import { AmountDisplay } from '@/components/ui/amount-display';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ChangeSeriesType } from '@/hooks/use-chart-views';
import {
    ChangeDataPoint,
    formatPercentValue,
    PercentDataPoint,
} from '@/lib/chart-calculations';
import { cn } from '@/lib/utils';
import {
    Bar,
    BarChart,
    Cell,
    ReferenceLine,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface ChangePercentChartProps {
    data: PercentDataPoint[] | ChangeDataPoint[];
    seriesType: ChangeSeriesType;
    onSeriesTypeChange: (type: ChangeSeriesType) => void;
    seriesKey: 'percent' | 'change';
    currencyCode: string;
    xAxisFormatter?: (value: string) => string;
    className?: string;
}

const seriesConfig: Record<ChangeSeriesType, { label: string; short: string }> =
    {
        mom: { label: 'Month over Month', short: 'MoM' },
        yoy: { label: 'Year over Year', short: 'YoY' },
        rolling12m: { label: 'Rolling 12M Change', short: '12M EUR' },
        rolling12m_pct: { label: 'Rolling 12M %', short: '12M %' },
    };

const chartConfig: ChartConfig = {
    change: {
        label: 'Change',
        color: 'var(--color-chart-1)',
    },
};

interface TooltipPayloadItem {
    payload?: {
        month?: string;
        value?: number;
        percent?: number | null;
        change?: number | null;
    };
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: TooltipPayloadItem[];
    seriesKey: 'percent' | 'change';
    currencyCode: string;
}

function CustomTooltip({
    active,
    payload,
    seriesKey,
    currencyCode,
}: CustomTooltipProps) {
    if (!active || !payload?.length) return null;

    const data = payload[0].payload;
    if (!data) return null;

    const value = seriesKey === 'percent' ? data.percent : data.change;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{data.month}</div>
            <div className="mt-1 flex items-center justify-between gap-4">
                <span className="text-muted-foreground">Change</span>
                <span
                    className={cn(
                        'font-mono font-medium tabular-nums',
                        value !== null && value > 0 && 'text-emerald-600',
                        value !== null && value < 0 && 'text-red-600',
                    )}
                >
                    {seriesKey === 'percent' ? (
                        formatPercentValue(value as number | null)
                    ) : (
                        <AmountDisplay
                            amountInCents={value ?? 0}
                            currencyCode={currencyCode}
                            minimumFractionDigits={0}
                            maximumFractionDigits={0}
                        />
                    )}
                </span>
            </div>
        </div>
    );
}

export function ChangePercentChart({
    data,
    seriesType,
    onSeriesTypeChange,
    seriesKey,
    currencyCode,
    xAxisFormatter,
    className,
}: ChangePercentChartProps) {
    // Filter out null values for rendering
    const chartData = data.map((point) => {
        const value =
            seriesKey === 'percent'
                ? (point as PercentDataPoint).percent
                : (point as ChangeDataPoint).change;
        return {
            ...point,
            displayValue: value,
        };
    });

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-end">
                <ToggleGroup
                    type="single"
                    value={seriesType}
                    onValueChange={(v) => {
                        if (v) onSeriesTypeChange(v as ChangeSeriesType);
                    }}
                    variant="outline"
                    size="sm"
                >
                    {Object.entries(seriesConfig).map(([key, config]) => (
                        <ToggleGroupItem
                            key={key}
                            value={key}
                            className="px-2 text-xs"
                        >
                            {config.short}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>
            <ChartContainer config={chartConfig} className={className}>
                <BarChart accessibilityLayer data={chartData}>
                    <XAxis
                        dataKey="month"
                        tickLine={false}
                        tickMargin={10}
                        axisLine={false}
                        tickFormatter={xAxisFormatter}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        tickFormatter={(value: number) => {
                            if (seriesKey === 'percent') {
                                return `${value.toFixed(0)}%`;
                            }
                            return new Intl.NumberFormat('en-US', {
                                notation: 'compact',
                                compactDisplay: 'short',
                            }).format(value / 100);
                        }}
                        width={50}
                    />
                    <ReferenceLine
                        y={0}
                        stroke="var(--color-border)"
                        strokeDasharray="3 3"
                    />
                    <Tooltip
                        content={
                            <CustomTooltip
                                seriesKey={seriesKey}
                                currencyCode={currencyCode}
                            />
                        }
                        cursor={{ fill: 'var(--color-muted)', opacity: 0.3 }}
                    />
                    <Bar dataKey="displayValue" radius={[4, 4, 0, 0]}>
                        {chartData.map((entry, index) => {
                            const value = entry.displayValue;
                            let fill = 'var(--color-muted)';
                            if (value !== null) {
                                fill =
                                    value >= 0
                                        ? 'var(--color-chart-2)'
                                        : 'var(--color-chart-5)';
                            }
                            return <Cell key={`cell-${index}`} fill={fill} />;
                        })}
                    </Bar>
                </BarChart>
            </ChartContainer>
        </div>
    );
}
