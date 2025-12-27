import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import { formatPercentValue, PercentDataPoint } from '@/lib/chart-calculations';
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

interface MoMPercentChartProps {
    data: PercentDataPoint[];
    xAxisFormatter?: (value: string) => string;
    className?: string;
}

const chartConfig: ChartConfig = {
    percent: {
        label: 'Change %',
        color: 'var(--color-chart-1)',
    },
};

interface TooltipPayloadItem {
    payload?: {
        month?: string;
        value?: number;
        percent?: number | null;
    };
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: TooltipPayloadItem[];
}

function CustomTooltip({ active, payload }: CustomTooltipProps) {
    if (!active || !payload?.length) return null;

    const data = payload[0].payload;
    if (!data) return null;

    const percent = data.percent;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{data.month}</div>
            <div className="mt-1 flex items-center justify-between gap-4">
                <span className="text-muted-foreground">Change</span>
                <span
                    className={cn(
                        'font-mono font-medium tabular-nums',
                        percent !== null && percent > 0 && 'text-emerald-600',
                        percent !== null && percent < 0 && 'text-red-600',
                    )}
                >
                    {formatPercentValue(percent ?? null)}
                </span>
            </div>
        </div>
    );
}

export function MoMPercentChart({
    data,
    xAxisFormatter,
    className,
}: MoMPercentChartProps) {
    const chartData = data.map((point) => ({
        ...point,
        displayValue: point.percent,
    }));

    return (
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
                    tickFormatter={(value: number) => `${value.toFixed(0)}%`}
                    width={50}
                />
                <ReferenceLine
                    y={0}
                    stroke="var(--color-border)"
                    strokeDasharray="3 3"
                />
                <Tooltip
                    content={<CustomTooltip />}
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
    );
}
