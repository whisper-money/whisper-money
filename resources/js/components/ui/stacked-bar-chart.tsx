import { useEffect, useRef } from 'react';
import { Bar, BarChart, XAxis } from 'recharts';

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

export interface StackedBarChartProps<T extends Record<string, unknown>> {
    data: T[];
    dataKeys: string[];
    config: ChartConfig;
    xAxisKey: string;
    xAxisFormatter?: (value: string) => string;
    valueFormatter?: (value: number, accountId?: string) => string;
    accountCurrencies?: Record<string, string>;
    className?: string;
    showLegend?: boolean;
    minBarWidth?: number;
}

export function StackedBarChart<T extends Record<string, unknown>>({
    data,
    dataKeys,
    config,
    xAxisKey,
    xAxisFormatter,
    valueFormatter,
    accountCurrencies,
    className,
    showLegend = true,
    minBarWidth = 50,
}: StackedBarChartProps<T>) {
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
                className="h-full"
                style={{ minWidth: `${minChartWidth}px` }}
            >
                <BarChart accessibilityLayer data={data}>
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
                            />
                        }
                    />
                    {showLegend && (
                        <ChartLegend content={<ChartLegendContent />} />
                    )}
                    {dataKeys.map((key, index) => {
                        const isFirst = index === 0;
                        const isLast = index === dataKeys.length - 1;
                        const radius: [number, number, number, number] = [
                            isLast ? 4 : 0,
                            isLast ? 4 : 0,
                            isFirst ? 4 : 0,
                            isFirst ? 4 : 0,
                        ];

                        return (
                            <Bar
                                key={key}
                                dataKey={key}
                                stackId="stack"
                                fill={COLOR_SHADES[index % COLOR_SHADES.length]}
                                radius={radius}
                            />
                        );
                    })}
                </BarChart>
            </ChartContainer>
        </div>
    );
}
