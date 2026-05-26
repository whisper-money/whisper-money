import { useEffect, useMemo, useRef } from 'react';
import { Bar, BarChart, Rectangle, XAxis, type BarShapeProps } from 'recharts';

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

const BORDER_RADIUS = 4;

interface StackedBarShapeProps {
    x: number;
    y: number;
    width: number;
    height: number;
    fill?: string;
    payload?: Record<string, unknown>;
    dataKey: string;
    dataKeys: string[];
}

export function StackedBarShape({
    x,
    y,
    width,
    height,
    fill,
    payload,
    dataKey,
    dataKeys,
}: StackedBarShapeProps) {
    if (height <= 0) return null;

    const segmentPayload = payload ?? {};
    const radius = Math.min(BORDER_RADIUS, width / 2, height / 2);
    const visibleKeys = dataKeys.filter((key) => {
        const value = segmentPayload[key];
        return typeof value === 'number' && value > 0;
    });

    const isFirstVisible = visibleKeys[0] === dataKey;
    const isLastVisible = visibleKeys[visibleKeys.length - 1] === dataKey;

    let path: string;

    if (isFirstVisible && isLastVisible) {
        path = `
            M ${x + radius} ${y}
            H ${x + width - radius}
            Q ${x + width} ${y} ${x + width} ${y + radius}
            V ${y + height - radius}
            Q ${x + width} ${y + height} ${x + width - radius} ${y + height}
            H ${x + radius}
            Q ${x} ${y + height} ${x} ${y + height - radius}
            V ${y + radius}
            Q ${x} ${y} ${x + radius} ${y}
            Z
        `;
    } else if (isLastVisible) {
        path = `
            M ${x + radius} ${y}
            H ${x + width - radius}
            Q ${x + width} ${y} ${x + width} ${y + radius}
            V ${y + height}
            H ${x}
            V ${y + radius}
            Q ${x} ${y} ${x + radius} ${y}
            Z
        `;
    } else if (isFirstVisible) {
        path = `
            M ${x} ${y}
            H ${x + width}
            V ${y + height - radius}
            Q ${x + width} ${y + height} ${x + width - radius} ${y + height}
            H ${x + radius}
            Q ${x} ${y + height} ${x} ${y + height - radius}
            V ${y}
            Z
        `;
    } else {
        path = `
            M ${x} ${y}
            H ${x + width}
            V ${y + height}
            H ${x}
            V ${y}
            Z
        `;
    }

    return (
        <path
            d={path}
            fill={fill ?? 'currentColor'}
            stroke="var(--card)"
            strokeLinejoin="round"
            strokeWidth={1}
        />
    );
}

const CustomCursor = (props: React.ComponentProps<typeof Rectangle>) => (
    <Rectangle {...props} fillOpacity={0.25} radius={5} />
);

export interface StackedBarChartProps<T extends Record<string, unknown>> {
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

export function StackedBarChart<T extends Record<string, unknown>>({
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
    minBarWidth = 50,
    netWorthMode,
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

    const shapeRenderers = useMemo(() => {
        return dataKeys.reduce(
            (acc, key) => {
                acc[key] = (props: BarShapeProps) => (
                    <StackedBarShape
                        {...props}
                        dataKey={key}
                        dataKeys={dataKeys}
                    />
                );

                return acc;
            },
            {} as Record<
                string,
                (props: BarShapeProps) => React.ReactElement | null
            >,
        );
    }, [dataKeys]);

    return (
        <div
            ref={scrollContainerRef}
            className={cn('overflow-x-auto', className)}
        >
            <ChartContainer
                config={configWithColors}
                className="w-full h-full"
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
                        cursor={<CustomCursor />}
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
                    {dataKeys.map((key, index) => (
                        <Bar
                            key={key}
                            dataKey={key}
                            stackId="stack"
                            fill={COLOR_SHADES[index % COLOR_SHADES.length]}
                            shape={shapeRenderers[key]}
                        />
                    ))}
                </BarChart>
            </ChartContainer>
        </div>
    );
}
