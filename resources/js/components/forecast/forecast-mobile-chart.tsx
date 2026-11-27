import { Card, CardContent } from '@/components/ui/card';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { chartRoleColors } from '@/lib/chart-colors';
import {
    buildChartSeries,
    type ForecastChartPoint,
} from '@/lib/forecast/chart-series';
import {
    balanceSeverity,
    monthLongName,
    monthShortLabel,
} from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type ForecastAllData, type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { useMemo } from 'react';

const CLOSE = chartRoleColors.accountMainLineColor;
const HEIGHT = 120;
const BAR_HEIGHT = 90;

interface ForecastMobileChartProps {
    forecast: ForecastData | ForecastAllData;
    /** The balance the bars are read against: below it they turn amber. */
    threshold: number | null;
    selectedMonth: string | null;
    onSelectMonth: (month: string) => void;
}

/**
 * The months the phone's chart draws: the last closed month, for context,
 * then the month in progress, the projected months and the locked ones.
 */
export function mobileChartPoints(
    forecast: ForecastData | ForecastAllData,
): ForecastChartPoint[] {
    const series = buildChartSeries(forecast);
    const past = series.filter((point) => point.status === 'past');

    return [
        ...past.slice(-1),
        ...series.filter((point) => point.status !== 'past'),
    ];
}

const BAR_COLORS = {
    ok: 'var(--muted-foreground)',
    warning: 'var(--warning)',
    critical: 'var(--destructive)',
} as const;

/** Red below zero, amber below the wanted minimum, grey otherwise. */
function barColor(point: ForecastChartPoint, threshold: number | null): string {
    return point.min === null
        ? 'var(--muted)'
        : BAR_COLORS[balanceSeverity(point.min, threshold)];
}

/** A projected bar is hatched; a real one is solid. */
function barBackground(point: ForecastChartPoint, color: string): string {
    if (point.status === 'past') {
        return color;
    }

    return `repeating-linear-gradient(135deg, color-mix(in oklab, ${color} 60%, transparent) 0 3px, color-mix(in oklab, ${color} 20%, transparent) 3px 6px)`;
}

/** Each month's close and its likely range, as SVG points across a 1000-wide box. */
function linePaths(points: ForecastChartPoint[]) {
    const values = points.flatMap((point) =>
        point.band
            ? [...point.band]
            : point.close === null
              ? []
              : [point.close],
    );
    if (values.length === 0) {
        return { line: '', band: '' };
    }

    const low = Math.min(...values);
    const high = Math.max(...values);
    const span = high - low || 1;
    const x = (index: number) => ((index + 0.5) / points.length) * 1000;
    const y = (value: number) =>
        HEIGHT - 10 - ((value - low) / span) * (HEIGHT - 20);
    const drawn = points
        .map((point, index) => ({ point, index }))
        .filter(({ point }) => point.close !== null);
    const at = (index: number, value: number) =>
        `${x(index).toFixed(1)},${y(value).toFixed(1)}`;

    return {
        line: drawn
            .map(({ point, index }) => at(index, point.close as number))
            .join(' '),
        band: [
            ...drawn.map(({ point, index }) =>
                at(index, point.band?.[1] ?? (point.close as number)),
            ),
            ...drawn
                .map(({ point, index }) =>
                    at(index, point.band?.[0] ?? (point.close as number)),
                )
                .reverse(),
        ].join(' '),
    };
}

function barHeight(point: ForecastChartPoint, highest: number): number {
    if (point.min === null) {
        return 12;
    }

    return Math.max(
        4,
        Math.round((Math.max(point.min, 0) / highest) * BAR_HEIGHT),
    );
}

/**
 * The phone's chart: a bar per month for its lowest balance, coloured when
 * it runs short, under the closing balance and its likely range. Tapping a
 * month picks it, as the month chips do.
 */
export function ForecastMobileChart({
    forecast,
    threshold,
    selectedMonth,
    onSelectMonth,
}: ForecastMobileChartProps) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const points = useMemo(() => mobileChartPoints(forecast), [forecast]);
    const paths = useMemo(() => linePaths(points), [points]);
    const highest = Math.max(1, ...points.map((point) => point.min ?? 0));

    return (
        <Card size="sm" className="gap-2" data-test="forecast-mobile-chart">
            <CardContent className="flex flex-col gap-2">
                <span className="text-xs text-muted-foreground">
                    {__('Closing balance and likely range · tap a month')}
                </span>
                <div className="relative flex h-[120px]">
                    <svg
                        viewBox={`0 0 1000 ${HEIGHT}`}
                        preserveAspectRatio="none"
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 size-full"
                    >
                        <polygon
                            points={paths.band}
                            fill={CLOSE}
                            fillOpacity={0.14}
                        />
                    </svg>
                    {points.map((point) => {
                        const selected = point.month === selectedMonth;
                        const color = barColor(point, threshold);

                        return (
                            <button
                                key={point.month}
                                type="button"
                                disabled={point.status === 'past'}
                                aria-pressed={selected}
                                aria-label={
                                    point.min === null
                                        ? monthLongName(point.month, locale)
                                        : `${monthLongName(point.month, locale)} · ${formatAmount(point.min, forecast.currencyCode, { fractionDigits: 0 })}`
                                }
                                onClick={() => onSelectMonth(point.month)}
                                className={cn(
                                    'relative flex min-w-0 flex-1 flex-col items-center justify-end rounded-t-md',
                                    selected && 'bg-muted',
                                )}
                                data-test={`forecast-mobile-bar-${point.month}`}
                            >
                                <span
                                    className={cn(
                                        'w-3/5 rounded-t-[2px]',
                                        point.status === 'locked' &&
                                            'border border-dashed border-muted-foreground/40',
                                    )}
                                    style={{
                                        height: barHeight(point, highest),
                                        background: barBackground(point, color),
                                    }}
                                />
                            </button>
                        );
                    })}
                    <svg
                        viewBox={`0 0 1000 ${HEIGHT}`}
                        preserveAspectRatio="none"
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 size-full"
                    >
                        <polyline
                            points={paths.line}
                            fill="none"
                            stroke={CLOSE}
                            strokeWidth={2}
                            vectorEffect="non-scaling-stroke"
                        />
                    </svg>
                </div>
                <div className="flex" aria-hidden="true">
                    {points.map((point) => (
                        <span
                            key={point.month}
                            className={cn(
                                'flex-1 text-center text-[9px] text-muted-foreground uppercase',
                                point.month === selectedMonth &&
                                    'font-bold text-foreground',
                            )}
                        >
                            {monthShortLabel(point.month, locale).charAt(0)}
                        </span>
                    ))}
                </div>
                <span className="text-[11px] text-muted-foreground">
                    <BarsLegend
                        threshold={threshold}
                        currencyCode={forecast.currencyCode}
                    />
                </span>
            </CardContent>
        </Card>
    );
}

function BarsLegend({
    threshold,
    currencyCode,
}: {
    threshold: number | null;
    currencyCode: string;
}) {
    const { formatAmount } = useFormatAmount();

    if (threshold !== null && threshold > 0) {
        return __(
            'Bars: the lowest balance of the month. Red, below 0; amber, below :minimum.',
            {
                minimum: formatAmount(threshold, currencyCode, {
                    fractionDigits: 0,
                }),
            },
        );
    }

    return __('Bars: the lowest balance of the month. Red, below 0.');
}
