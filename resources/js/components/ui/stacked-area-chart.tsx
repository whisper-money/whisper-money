import { useEffect, useRef } from 'react';
import { Area, AreaChart, XAxis } from 'recharts';

import {
    ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { cn } from '@/lib/utils';

const COLOR_SHADES: string[] = [
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
    'var(--color-chart-5)',
    'var(--color-chart-6)',
    'var(--color-chart-7)',
    'var(--color-chart-8)',
    'var(--color-chart-9)',
    'var(--color-chart-10)',
    'var(--color-chart-1)',
];

export interface StackedAreaChartProps<T extends Record<string, unknown>> {
    data: T[];
    dataKeys: string[];
    config: ChartConfig;
    xAxisKey: string;
    xAxisFormatter?: (value: string) => string;
    valueFormatter?: (value: number, accountId?: string) => React.ReactNode;
    accountCurrencies?: Record<string, string>;
    displayCurrency?: string;
    className?: string;
    showLegend?: boolean;
    minBarWidth?: number;
    netWorthMode?: { liabilityTypeLabel: string; liabilityDotColor?: string };
}

export function StackedAreaChart<T extends Record<string, unknown>>({
    data,
    dataKeys,
    config,
    xAxisKey,
    xAxisFormatter,
    valueFormatter,
    accountCurrencies,
    displayCurrency,
    className,
    showLegend = true,
    minBarWidth = 20,
    netWorthMode,
}: StackedAreaChartProps<T>) {
    const scrollContainerRef = useRef<HTMLDivElement>(null);

    const configWithColors: ChartConfig = Object.fromEntries(
        Object.entries(config).map(([key, value], index) => [
            key,
            {
                ...value,
                color: COLOR_SHADES[index % COLOR_SHADES.length],
            },
        ]),
    );

    const minChartWidth = data.length * minBarWidth;

    useEffect(() => {
        if (scrollContainerRef.current) {
            scrollContainerRef.current.scrollLeft =
                scrollContainerRef.current.scrollWidth;
        }
    }, [data]);

    return (
        <div
            ref={scrollContainerRef}
            className={cn('overflow-x-auto', className)}
        >
            <ChartContainer
                config={configWithColors}
                className="h-full w-full"
                style={{ minWidth: `${minChartWidth}px` }}
            >
                <AreaChart accessibilityLayer data={data}>
                    <defs>
                        {dataKeys.map((key, index) => {
                            const color =
                                COLOR_SHADES[index % COLOR_SHADES.length];
                            return (
                                <linearGradient
                                    key={key}
                                    id={`fill-${key}`}
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="5%"
                                        stopColor={color}
                                        stopOpacity={0.3}
                                    />
                                    <stop
                                        offset="95%"
                                        stopColor={color}
                                        stopOpacity={0.05}
                                    />
                                </linearGradient>
                            );
                        })}
                    </defs>
                    <XAxis
                        dataKey={xAxisKey}
                        tickLine={false}
                        tickMargin={10}
                        axisLine={false}
                        tickFormatter={xAxisFormatter}
                    />
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                hideLabel
                                valueFormatter={valueFormatter}
                                accountCurrencies={accountCurrencies}
                                displayCurrency={displayCurrency}
                                netWorthMode={netWorthMode}
                            />
                        }
                    />
                    {showLegend && (
                        <ChartLegend content={<ChartLegendContent />} />
                    )}
                    {dataKeys.map((key, index) => {
                        const color =
                            COLOR_SHADES[index % COLOR_SHADES.length];
                        return (
                            <Area
                                key={key}
                                dataKey={key}
                                stackId="stack"
                                type="monotone"
                                fill={`url(#fill-${key})`}
                                stroke={color}
                                strokeWidth={2}
                                dot={false}
                                activeDot={{ r: 4 }}
                                fillOpacity={1}
                            />
                        );
                    })}
                </AreaChart>
            </ChartContainer>
        </div>
    );
}
