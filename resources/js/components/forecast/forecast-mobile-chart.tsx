import { ForecastChartTooltipCard } from '@/components/forecast/forecast-chart-tooltip';
import { Card, CardContent } from '@/components/ui/card';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { chartRoleColors } from '@/lib/chart-colors';
import {
    buildChartSeries,
    type ForecastChartPoint,
} from '@/lib/forecast/chart-series';
import {
    chartTooltipData,
    mobileTooltipLeft,
} from '@/lib/forecast/chart-tooltip';
import {
    balanceSeverity,
    monthLongName,
    monthShortLabel,
} from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type ForecastAllData, type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import {
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
    type RefObject,
} from 'react';

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
 * month picks it, as the month chips do, and shows the desktop chart's
 * tooltip over its bar; the last closed month only shows the tooltip.
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
    const barsRef = useRef<HTMLDivElement>(null);
    const [tooltipMonth, setTooltipMonth] = useBarTooltip(barsRef);
    const tooltipPoint = points.find((point) => point.month === tooltipMonth);
    const tooltipData = tooltipPoint
        ? chartTooltipData(tooltipPoint, forecast)
        : null;

    const tapMonth = (point: ForecastChartPoint) => {
        if (point.status !== 'past') {
            onSelectMonth(point.month);
        }

        setTooltipMonth((open) =>
            open === point.month || point.status === 'locked'
                ? null
                : point.month,
        );
    };

    return (
        <Card size="sm" className="gap-2" data-test="forecast-mobile-chart">
            <CardContent className="flex flex-col gap-2">
                <span className="text-xs text-muted-foreground">
                    {__('Closing balance and likely range · tap a month')}
                </span>
                <div ref={barsRef} className="relative flex h-[120px]">
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
                                aria-pressed={
                                    point.status === 'past'
                                        ? undefined
                                        : selected
                                }
                                aria-label={
                                    point.min === null
                                        ? monthLongName(point.month, locale)
                                        : `${monthLongName(point.month, locale)} · ${formatAmount(point.min, forecast.currencyCode, { fractionDigits: 0 })}`
                                }
                                onClick={() => tapMonth(point)}
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
                    <MobileChartTooltip
                        barsRef={barsRef}
                        month={tooltipData === null ? null : tooltipMonth}
                    >
                        {tooltipData && (
                            <ForecastChartTooltipCard
                                data={tooltipData}
                                forecast={forecast}
                            />
                        )}
                    </MobileChartTooltip>
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

/**
 * The month whose tooltip is open, closed again when the user taps anywhere
 * but the bars (a tap on a bar decides for itself), scrolls or turns the phone.
 */
function useBarTooltip(barsRef: RefObject<HTMLElement | null>) {
    const [month, setMonth] = useState<string | null>(null);
    const open = month !== null;

    useEffect(() => {
        if (!open) {
            return;
        }

        const dismiss = () => setMonth(null);
        const dismissOutside = (event: PointerEvent) => {
            if (!barsRef.current?.contains(event.target as Node)) {
                dismiss();
            }
        };

        document.addEventListener('pointerdown', dismissOutside);
        // The page's scroll only: the month chips scroll their own strip to
        // the picked month, which must not close the tooltip it just opened.
        window.addEventListener('scroll', dismiss);
        window.addEventListener('resize', dismiss);

        return () => {
            document.removeEventListener('pointerdown', dismissOutside);
            window.removeEventListener('scroll', dismiss);
            window.removeEventListener('resize', dismiss);
        };
    }, [barsRef, open]);

    return [month, setMonth] as const;
}

/**
 * The tooltip's place over the bars: its bottom just above them, centred on
 * the tapped month and kept inside the screen (it is absolute inside the
 * bars, hence the offset from their left). It is a polite live region,
 * so a screen reader reads the card out when a tap opens it.
 */
function MobileChartTooltip({
    barsRef,
    month,
    children,
}: {
    barsRef: RefObject<HTMLElement | null>;
    month: string | null;
    children: ReactNode;
}) {
    const tooltipRef = useRef<HTMLDivElement>(null);
    const [left, setLeft] = useState<number | null>(null);

    useLayoutEffect(() => {
        const bars = barsRef.current;
        const bar = bars?.querySelector(
            `[data-test="forecast-mobile-bar-${month}"]`,
        );
        const tooltip = tooltipRef.current;

        if (!bars || !bar || !tooltip) {
            setLeft(null);

            return;
        }

        const barRect = bar.getBoundingClientRect();

        setLeft(
            mobileTooltipLeft({
                barCenter: barRect.left + barRect.width / 2,
                chartLeft: bars.getBoundingClientRect().left,
                tooltipWidth: tooltip.offsetWidth,
                screenWidth: document.documentElement.clientWidth,
            }),
        );
    }, [barsRef, month]);

    return (
        <div
            ref={tooltipRef}
            aria-live="polite"
            className="pointer-events-none absolute bottom-[calc(100%+0.5rem)] z-50 w-max"
            style={{
                left: left ?? 0,
                opacity: left === null ? 0 : 1,
            }}
            data-test="forecast-mobile-chart-tooltip"
        >
            {children}
        </div>
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
