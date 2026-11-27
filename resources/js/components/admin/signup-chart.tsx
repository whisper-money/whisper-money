import { index } from '@/actions/App/Http/Controllers/AdminController';
import { PeriodNavigation } from '@/components/cashflow/period-navigation';
import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import HeadingSmall from '@/components/heading-small';
import { Card, CardContent } from '@/components/ui/card';
import {
    type ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipPortal,
} from '@/components/ui/chart';
import { Label } from '@/components/ui/label';
import {
    StatTile,
    StatTileDescription,
    StatTileLabel,
    StatTileTrend,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { Switch } from '@/components/ui/switch';
import { useLocale } from '@/hooks/use-locale';
import {
    formatPeriodParam,
    parsePeriodParam,
    type PeriodType,
} from '@/lib/period-param';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { parseISO } from 'date-fns';
import { useState } from 'react';
import { Bar, ComposedChart, Line, XAxis, YAxis } from 'recharts';

export type SignupChartPoint = {
    /** First UTC day of the bucket, `yyyy-MM-dd`. */
    start: string;
    /** Last UTC day of the bucket; the same as `start` for a day. */
    end: string;
    /** Null for a bucket after today. */
    signups: number | null;
    /** Distinct users active in the 30 days up to the bucket's end (or today). */
    active_users: number | null;
    /** The bucket at the same index of the previous period; null past its end. */
    previous_start: string | null;
    previous_end: string | null;
    previous_signups: number | null;
    previous_active_users: number | null;
};

export type SignupChartData = {
    period_type: PeriodType;
    period: string;
    previous_period: string;
    bucket: 'day' | 'week';
    points: SignupChartPoint[];
    summary: {
        signups: number;
        active_users: number;
        previous_signups: number;
        previous_active_users: number;
    };
};

const COMPARE_SWITCH_ID = 'signup-chart-compare';

function chartConfig(): ChartConfig {
    return {
        signups: { label: __('Sign-ups'), color: 'var(--color-chart-1)' },
        active_users: {
            label: __('Active in the last 30 days'),
            color: 'var(--color-chart-3)',
        },
        previous_signups: {
            label: __('Sign-ups, previous period'),
            color: 'color-mix(in oklch, var(--color-chart-1) 40%, transparent)',
        },
        previous_active_users: {
            label: __('Active, previous period'),
            color: 'var(--color-chart-3)',
        },
    };
}

/** A percentage change, or null when there is nothing to compare with. */
function percentChange(current: number, previous: number): number | null {
    return previous > 0 ? ((current - previous) / previous) * 100 : null;
}

/**
 * Moves the chart to another period: only `signupChart` is asked for again,
 * so the rest of /admin is not recomputed, and the URL keeps the period.
 */
function visitPeriod(date: Date, periodType: PeriodType): void {
    router.get(
        index.url({
            query: {
                period_type: periodType,
                period: formatPeriodParam(date, periodType),
            },
        }),
        {},
        {
            only: ['signupChart'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function useBucketLabel(bucket: SignupChartData['bucket']) {
    const locale = useLocale();
    const day = (date: string, pattern: string) =>
        formatDate(parseISO(date), pattern, locale);

    return {
        tick: (date: string) => day(date, 'MMM d'),
        full: (start: string, end: string) =>
            bucket === 'day' || start === end
                ? day(start, 'MMM d, yyyy')
                : `${day(start, 'MMM d')} – ${day(end, 'MMM d, yyyy')}`,
        day,
    };
}

type TooltipRow = { key: string; color: string; value: number | null };

function TooltipGroup({ label, rows }: { label: string; rows: TooltipRow[] }) {
    const config = chartConfig();

    return (
        <div className="grid gap-1.5">
            <div className="font-medium">{label}</div>
            {rows.map((row) => (
                <div
                    key={row.key}
                    className="flex items-center justify-between gap-4 leading-none"
                >
                    <div className="flex min-w-0 items-center gap-2">
                        <div
                            className="size-2.5 shrink-0 rounded-xs"
                            style={{ backgroundColor: row.color }}
                        />
                        <span className="truncate text-muted-foreground">
                            {config[row.key]?.label}
                        </span>
                    </div>
                    <span className="font-mono font-medium text-foreground tabular-nums">
                        {row.value === null ? '—' : row.value.toLocaleString()}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * The hovered bucket, and with the comparison on the bucket at the same
 * index of the previous period, each under its own date.
 */
function SignupChartTooltip({
    active,
    payload,
    coordinate,
    compare,
    bucket,
}: {
    active?: boolean;
    payload?: Array<{ payload?: SignupChartPoint }>;
    coordinate?: { x?: number; y?: number };
    compare: boolean;
    bucket: SignupChartData['bucket'];
}) {
    const label = useBucketLabel(bucket);
    const point = payload?.[0]?.payload;

    if (!active || !point) {
        return null;
    }

    const showCurrent = point.signups !== null;
    const showPrevious = compare && point.previous_start !== null;

    if (!showCurrent && !showPrevious) {
        return null;
    }

    return (
        <ChartTooltipPortal coordinate={coordinate}>
            <div className="grid max-w-[min(20rem,calc(100vw-2rem))] min-w-[8rem] gap-2.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
                {showCurrent && (
                    <TooltipGroup
                        label={label.full(point.start, point.end)}
                        rows={[
                            {
                                key: 'signups',
                                color: 'var(--color-signups)',
                                value: point.signups,
                            },
                            {
                                key: 'active_users',
                                color: 'var(--color-active_users)',
                                value: point.active_users,
                            },
                        ]}
                    />
                )}
                {showPrevious && (
                    <TooltipGroup
                        label={label.full(
                            point.previous_start!,
                            point.previous_end!,
                        )}
                        rows={[
                            {
                                key: 'previous_signups',
                                color: 'var(--color-previous_signups)',
                                value: point.previous_signups,
                            },
                            {
                                key: 'previous_active_users',
                                color: 'var(--color-previous_active_users)',
                                value: point.previous_active_users,
                            },
                        ]}
                    />
                )}
            </div>
        </ChartTooltipPortal>
    );
}

function SignupSummary({
    chart,
    compare,
}: {
    chart: SignupChartData;
    compare: boolean;
}) {
    const label = useBucketLabel(chart.bucket);
    const { summary } = chart;
    const lastDrawn = chart.points
        .filter((point) => point.signups !== null)
        .at(-1);
    const tiles = [
        {
            key: 'signups',
            label: __('Sign-ups'),
            value: summary.signups,
            previous: summary.previous_signups,
            description: null,
        },
        {
            key: 'active_users',
            label: __('Active users'),
            value: summary.active_users,
            previous: summary.previous_active_users,
            description: lastDrawn
                ? __('In the 30 days up to :date', {
                      date: label.day(lastDrawn.end, 'MMM d, yyyy'),
                  })
                : null,
        },
    ];

    return (
        <div className="grid grid-cols-2 gap-4">
            {tiles.map((tile) => (
                <StatTile key={tile.key} size="lg">
                    <StatTileLabel>{tile.label}</StatTileLabel>
                    <StatTileValue>{tile.value.toLocaleString()}</StatTileValue>
                    {compare && (
                        <StatTileTrend>
                            <PercentageTrendIndicator
                                trend={percentChange(tile.value, tile.previous)}
                                label={__('vs last period')}
                            />
                        </StatTileTrend>
                    )}
                    {tile.description && (
                        <StatTileDescription>
                            {tile.description}
                        </StatTileDescription>
                    )}
                </StatTile>
            ))}
        </div>
    );
}

/**
 * Sign-ups per bucket as bars, and the trailing 30-day active users as a line
 * on its own axis: the two differ by orders of magnitude. The comparison draws
 * the previous period at the same index: fainter bars and a dashed line.
 */
export function SignupChart({ chart }: { chart: SignupChartData }) {
    const [compare, setCompare] = useState(false);
    const label = useBucketLabel(chart.bucket);
    const currentDate = parsePeriodParam(chart.period, chart.period_type);

    return (
        <section className="space-y-4">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <HeadingSmall
                    title={__('Sign-ups')}
                    description={
                        chart.bucket === 'week'
                            ? __('By week, in UTC.')
                            : __('By day, in UTC.')
                    }
                />
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <PeriodNavigation
                        currentDate={currentDate}
                        periodType={chart.period_type}
                        onDateChange={(date) =>
                            visitPeriod(date, chart.period_type)
                        }
                        onPeriodTypeChange={(periodType) =>
                            visitPeriod(currentDate, periodType)
                        }
                    />
                    <div className="flex h-11 items-center gap-2 sm:h-9">
                        <Switch
                            id={COMPARE_SWITCH_ID}
                            checked={compare}
                            onCheckedChange={setCompare}
                        />
                        <Label htmlFor={COMPARE_SWITCH_ID}>
                            {__('Compare with previous period')}
                        </Label>
                    </div>
                </div>
            </div>
            <Card>
                <CardContent className="flex flex-col gap-6">
                    <SignupSummary chart={chart} compare={compare} />
                    <ChartContainer
                        config={chartConfig()}
                        className="aspect-auto h-64 w-full"
                    >
                        <ComposedChart data={chart.points}>
                            <XAxis
                                dataKey="start"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                minTickGap={24}
                                tickFormatter={label.tick}
                            />
                            <YAxis
                                yAxisId="signups"
                                tickLine={false}
                                axisLine={false}
                                width={32}
                                allowDecimals={false}
                            />
                            <YAxis
                                yAxisId="active_users"
                                orientation="right"
                                tickLine={false}
                                axisLine={false}
                                width={40}
                                allowDecimals={false}
                            />
                            <ChartTooltip
                                content={
                                    <SignupChartTooltip
                                        compare={compare}
                                        bucket={chart.bucket}
                                    />
                                }
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                            {compare && (
                                <Bar
                                    yAxisId="signups"
                                    dataKey="previous_signups"
                                    fill="var(--color-previous_signups)"
                                    radius={[3, 3, 0, 0]}
                                />
                            )}
                            <Bar
                                yAxisId="signups"
                                dataKey="signups"
                                fill="var(--color-signups)"
                                radius={[3, 3, 0, 0]}
                            />
                            {compare && (
                                <Line
                                    yAxisId="active_users"
                                    type="monotone"
                                    dataKey="previous_active_users"
                                    stroke="var(--color-previous_active_users)"
                                    strokeWidth={2}
                                    strokeDasharray="4 4"
                                    dot={false}
                                />
                            )}
                            <Line
                                yAxisId="active_users"
                                type="monotone"
                                dataKey="active_users"
                                stroke="var(--color-active_users)"
                                strokeWidth={2}
                                dot={false}
                            />
                        </ComposedChart>
                    </ChartContainer>
                </CardContent>
            </Card>
        </section>
    );
}
