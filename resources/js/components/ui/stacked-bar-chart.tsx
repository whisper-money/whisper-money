import { Bar, BarChart, XAxis } from 'recharts';

import {
    ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';

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
}: StackedBarChartProps<T>) {
    const configWithColors: ChartConfig = Object.fromEntries(
        Object.entries(config).map(([key, value], index) => [
            key,
            {
                ...value,
                color: COLOR_SHADES[index % COLOR_SHADES.length],
            },
        ]),
    );

    return (
        <ChartContainer config={configWithColors} className={className}>
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
    );
}
