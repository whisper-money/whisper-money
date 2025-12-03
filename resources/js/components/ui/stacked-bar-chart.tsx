import { Bar, BarChart, XAxis } from 'recharts';

import {
    ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';

export type ColorPalette =
    | 'amber'
    | 'blue'
    | 'cyan'
    | 'emerald'
    | 'fuchsia'
    | 'gray'
    | 'green'
    | 'indigo'
    | 'lime'
    | 'neutral'
    | 'orange'
    | 'pink'
    | 'purple'
    | 'red'
    | 'rose'
    | 'slate'
    | 'stone'
    | 'teal'
    | 'violet'
    | 'yellow'
    | 'zinc';

const COLOR_SHADES: number[] = [700, 500, 300, 100, 600, 400, 800, 200, 900, 50];

export interface StackedBarChartProps<T extends Record<string, unknown>> {
    data: T[];
    dataKeys: string[];
    config: ChartConfig;
    color?: ColorPalette;
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
    color = 'zinc',
    xAxisKey,
    xAxisFormatter,
    valueFormatter,
    accountCurrencies,
    className,
    showLegend = true,
}: StackedBarChartProps<T>) {
    const shades = COLOR_SHADES.map(
        (shade) => `var(--color-${color}-${shade})`,
    );

    const configWithColors: ChartConfig = Object.fromEntries(
        Object.entries(config).map(([key, value], index) => [
            key,
            {
                ...value,
                color: shades[index % shades.length],
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
                            fill={shades[index % shades.length]}
                            radius={radius}
                        />
                    );
                })}
            </BarChart>
        </ChartContainer>
    );
}
