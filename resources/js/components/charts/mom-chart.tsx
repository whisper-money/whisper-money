import { AmountDisplay } from '@/components/ui/amount-display';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import { ChangeDataPoint } from '@/lib/chart-calculations';
import { cn } from '@/lib/utils';
import { useEffect, useRef } from 'react';
import {
    Bar,
    BarChart,
    Cell,
    ReferenceLine,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface MoMChartProps {
    data: ChangeDataPoint[];
    currencyCode: string;
    xAxisFormatter?: (value: string) => string;
    className?: string;
    minBarWidth?: number;
}

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
        change?: number | null;
    };
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

    const change = data.change;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{data.month}</div>
            <div className="mt-1 flex items-center justify-between gap-4">
                <span className="text-muted-foreground">Change</span>
                <span
                    className={cn(
                        'font-mono font-medium tabular-nums',
                        change !== null && change > 0 && 'text-emerald-600',
                        change !== null && change < 0 && 'text-red-600',
                    )}
                >
                    <AmountDisplay
                        amountInCents={change ?? 0}
                        currencyCode={currencyCode}
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                    />
                </span>
            </div>
        </div>
    );
}

export function MoMChart({
    data,
    currencyCode,
    xAxisFormatter,
    className,
    minBarWidth = 50,
}: MoMChartProps) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);
    const minChartWidth = data.length * minBarWidth;

    useEffect(() => {
        if (scrollContainerRef.current) {
            scrollContainerRef.current.scrollLeft =
                scrollContainerRef.current.scrollWidth;
        }
    }, [data]);

    const chartData = data.map((point) => ({
        ...point,
        displayValue: point.change,
    }));

    return (
        <div
            ref={scrollContainerRef}
            className={cn('overflow-x-auto', className)}
        >
            <ChartContainer
                config={chartConfig}
                className="h-full w-full"
                style={{ minWidth: `${minChartWidth}px` }}
            >
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
                        content={<CustomTooltip currencyCode={currencyCode} />}
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
