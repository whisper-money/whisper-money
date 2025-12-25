import { AmountDisplay } from '@/components/ui/amount-display';
import {
    ChartConfig,
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { ScaleType } from '@/hooks/use-chart-views';
import { MonthDataPoint } from '@/lib/chart-calculations';
import { AlertCircle } from 'lucide-react';
import { Line, LineChart, ReferenceLine, XAxis, YAxis } from 'recharts';

interface NetWorthLineChartProps {
    data: MonthDataPoint[];
    currencyCode: string;
    scaleType: ScaleType;
    onScaleTypeChange: (scale: ScaleType) => void;
    canUseLog: boolean;
    logScaleWarning: string | null;
    xAxisFormatter?: (value: string) => string;
    className?: string;
}

const chartConfig: ChartConfig = {
    netWorth: {
        label: 'Net Worth',
        color: 'var(--color-chart-1)',
    },
};

export function NetWorthLineChart({
    data,
    currencyCode,
    scaleType,
    onScaleTypeChange,
    canUseLog,
    logScaleWarning,
    xAxisFormatter,
    className,
}: NetWorthLineChartProps) {
    const valueFormatter = (value: number): React.ReactNode => {
        return (
            <AmountDisplay
                amountInCents={value}
                currencyCode={currencyCode}
                minimumFractionDigits={0}
                maximumFractionDigits={0}
            />
        );
    };

    const hasNegativeValues = data.some((d) => d.value < 0);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-end gap-2">
                <ToggleGroup
                    type="single"
                    value={scaleType}
                    onValueChange={(v) => {
                        if (v) onScaleTypeChange(v as ScaleType);
                    }}
                    variant="outline"
                    size="sm"
                >
                    <ToggleGroupItem value="linear" className="px-2 text-xs">
                        Linear
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="log"
                        className="px-2 text-xs"
                        disabled={!canUseLog}
                    >
                        Log
                    </ToggleGroupItem>
                </ToggleGroup>
                {!canUseLog && logScaleWarning && (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <AlertCircle className="size-4 text-muted-foreground" />
                        </TooltipTrigger>
                        <TooltipContent side="left" className="max-w-[200px]">
                            <p className="text-xs">{logScaleWarning}</p>
                        </TooltipContent>
                    </Tooltip>
                )}
            </div>
            <ChartContainer config={chartConfig} className={className}>
                <LineChart accessibilityLayer data={data}>
                    <XAxis
                        dataKey="month"
                        tickLine={false}
                        tickMargin={10}
                        axisLine={false}
                        tickFormatter={xAxisFormatter}
                    />
                    <YAxis
                        scale={scaleType}
                        domain={
                            scaleType === 'log' ? ['auto', 'auto'] : undefined
                        }
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
                    {hasNegativeValues && (
                        <ReferenceLine
                            y={0}
                            stroke="var(--color-border)"
                            strokeDasharray="3 3"
                        />
                    )}
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                hideLabel
                                valueFormatter={(v) =>
                                    valueFormatter(v as number)
                                }
                            />
                        }
                    />
                    <Line
                        type="monotone"
                        dataKey="value"
                        name="netWorth"
                        stroke="var(--color-chart-1)"
                        strokeWidth={2}
                        dot={false}
                        activeDot={{ r: 4, strokeWidth: 0 }}
                    />
                </LineChart>
            </ChartContainer>
        </div>
    );
}
