import { useEffect, useMemo, useRef } from 'react';
import {
    Bar,
    BarChart,
    Rectangle,
    ReferenceLine,
    XAxis,
    type BarShapeProps,
} from 'recharts';

import {
    ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    type NetWorthMode,
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
    /**
     * Keep the bottom edge square even for the bottom-most segment. Used when
     * the chart has a zero baseline shared with downward deficit bars, so the
     * positive stack meets the axis flush (rounded only at its far/top end).
     */
    flatBottom?: boolean;
}

/**
 * Build a rounded-rectangle path, rounding only the requested corners.
 * Traversal matches the original per-corner variants so an all-rounded or
 * top/bottom-only bar produces an identical `d` string.
 */
function roundedBarPath(
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
    roundTop: boolean,
    roundBottom: boolean,
): string {
    const rTop = roundTop ? radius : 0;
    const rBottom = roundBottom ? radius : 0;

    return [
        `M ${x + rTop} ${y}`,
        `H ${x + width - rTop}`,
        rTop ? `Q ${x + width} ${y} ${x + width} ${y + rTop}` : '',
        `V ${y + height - rBottom}`,
        rBottom
            ? `Q ${x + width} ${y + height} ${x + width - rBottom} ${y + height}`
            : '',
        `H ${x + rBottom}`,
        rBottom ? `Q ${x} ${y + height} ${x} ${y + height - rBottom}` : '',
        `V ${y + rTop}`,
        rTop ? `Q ${x} ${y} ${x + rTop} ${y}` : '',
        'Z',
    ]
        .filter(Boolean)
        .join(' ');
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
    flatBottom = false,
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

    const path = roundedBarPath(
        x,
        y,
        width,
        height,
        radius,
        isLastVisible,
        isFirstVisible && !flatBottom,
    );

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
    netWorthMode?: NetWorthMode;
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

    // When downward deficit bars share the zero baseline, keep the positive
    // stack square at the axis so both directions round only at their far end.
    const deficitKey = netWorthMode?.deficitKey;
    const hasDeficit = deficitKey
        ? data.some((point) => typeof point[deficitKey] === 'number')
        : false;

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
                        flatBottom={hasDeficit}
                    />
                );

                return acc;
            },
            {} as Record<
                string,
                (props: BarShapeProps) => React.ReactElement | null
            >,
        );
    }, [dataKeys, hasDeficit]);

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
                    {netWorthMode?.deficitKey && (
                        <ReferenceLine
                            y={0}
                            stroke="var(--color-border)"
                            strokeDasharray="3 3"
                        />
                    )}
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
                    {netWorthMode?.deficitKey && (
                        <Bar
                            key={netWorthMode.deficitKey}
                            dataKey={netWorthMode.deficitKey}
                            stackId="stack"
                            fill={
                                netWorthMode.liabilityDotColor ??
                                'var(--color-destructive)'
                            }
                            // Deficit values are negative, so Recharts flips the
                            // radius vertically: top corners round the far (bottom)
                            // end of the downward bar. Matches MoMChart.
                            radius={[BORDER_RADIUS, BORDER_RADIUS, 0, 0]}
                        />
                    )}
                </BarChart>
            </ChartContainer>
        </div>
    );
}
