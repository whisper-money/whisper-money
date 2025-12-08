import { useEffect, useMemo, useRef } from 'react';
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

const BORDER_RADIUS = 4;

interface StackedBarShapeProps {
    x: number;
    y: number;
    width: number;
    height: number;
    fill: string;
    payload: Record<string, unknown>;
    dataKey: string;
    dataKeys: string[];
}

function StackedBarShape({
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

    const visibleKeys = dataKeys.filter((key) => {
        const value = payload[key];
        return typeof value === 'number' && value > 0;
    });

    const isFirstVisible = visibleKeys[0] === dataKey;
    const isLastVisible = visibleKeys[visibleKeys.length - 1] === dataKey;

    let path: string;

    if (isFirstVisible && isLastVisible) {
        path = `
            M ${x + BORDER_RADIUS} ${y}
            H ${x + width - BORDER_RADIUS}
            Q ${x + width} ${y} ${x + width} ${y + BORDER_RADIUS}
            V ${y + height - BORDER_RADIUS}
            Q ${x + width} ${y + height} ${x + width - BORDER_RADIUS} ${y + height}
            H ${x + BORDER_RADIUS}
            Q ${x} ${y + height} ${x} ${y + height - BORDER_RADIUS}
            V ${y + BORDER_RADIUS}
            Q ${x} ${y} ${x + BORDER_RADIUS} ${y}
            Z
        `;
    } else if (isLastVisible) {
        path = `
            M ${x + BORDER_RADIUS} ${y}
            H ${x + width - BORDER_RADIUS}
            Q ${x + width} ${y} ${x + width} ${y + BORDER_RADIUS}
            V ${y + height}
            H ${x}
            V ${y + BORDER_RADIUS}
            Q ${x} ${y} ${x + BORDER_RADIUS} ${y}
            Z
        `;
    } else if (isFirstVisible) {
        path = `
            M ${x} ${y}
            H ${x + width}
            V ${y + height - BORDER_RADIUS}
            Q ${x + width} ${y + height} ${x + width - BORDER_RADIUS} ${y + height}
            H ${x + BORDER_RADIUS}
            Q ${x} ${y + height} ${x} ${y + height - BORDER_RADIUS}
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

    return <path d={path} fill={fill} />;
}

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

    const shapeRenderers = useMemo(() => {
        return dataKeys.reduce(
            (acc, key) => {
                acc[key] = (props: Record<string, unknown>) => (
                    <StackedBarShape
                        {...(props as Omit<StackedBarShapeProps, 'dataKey' | 'dataKeys'>)}
                        dataKey={key}
                        dataKeys={dataKeys}
                    />
                );
                return acc;
            },
            {} as Record<string, (props: Record<string, unknown>) => React.ReactNode>,
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
