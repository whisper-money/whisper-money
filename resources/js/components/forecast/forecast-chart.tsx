import { ForecastChartTooltip } from '@/components/forecast/forecast-chart-tooltip';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartConfig,
    ChartContainer,
    ChartTooltip,
} from '@/components/ui/chart';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { chartRoleColors } from '@/lib/chart-colors';
import { shortAccountsByMonth } from '@/lib/forecast/all-accounts';
import {
    buildChartSeries,
    type ForecastChartPoint,
} from '@/lib/forecast/chart-series';
import {
    balanceTone,
    isShort,
    monthLongName,
    monthShortLabel,
} from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type ForecastAllData, type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { useMemo } from 'react';
import {
    Area,
    Bar,
    Cell,
    ComposedChart,
    Curve,
    type CurveProps,
    Line,
    ReferenceArea,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';

const INCOME = chartRoleColors.cashflowIncomeColor;
const EXPENSE = chartRoleColors.cashflowExpenseColor;
const CLOSE = chartRoleColors.accountMainLineColor;

const BALANCE_AXIS = 'balance';

const TOOLTIP_COLORS = { income: INCOME, expenses: EXPENSE, close: CLOSE };

const chartConfig = {
    income: { label: 'Income', color: INCOME },
    expenses: { label: 'Expenses', color: EXPENSE },
    close: { label: 'Closing balance', color: CLOSE },
} satisfies ChartConfig;

interface ForecastChartProps {
    forecast: ForecastData | ForecastAllData;
    selectedMonth: string | null;
    onSelectMonth: (month: string) => void;
}

/** Whether a month has a column in the plan, so it can be picked. */
function isSelectable(point: ForecastChartPoint): boolean {
    return point.status === 'current' || point.status === 'future';
}

function barFill(point: ForecastChartPoint, series: 'income' | 'expenses') {
    if (point.status === 'locked') {
        return 'var(--muted)';
    }

    if (point.status === 'future') {
        return `url(#forecast-hatch-${series})`;
    }

    return series === 'income' ? INCOME : EXPENSE;
}

/**
 * The hover line over a month, left out on a month locked behind Pro, which
 * has no tooltip to go with it.
 */
function MonthCursor({
    months,
    payloadIndex,
    ...props
}: CurveProps & {
    months: ForecastChartPoint[];
    payloadIndex?: string | number | null;
}) {
    const point = months[Number(payloadIndex)];

    if (!point || point.status === 'locked') {
        return null;
    }

    return <Curve {...props} />;
}

function minTone(min: number, threshold: number | null): string {
    return isShort(min, threshold)
        ? `font-semibold ${balanceTone(min, threshold)}`
        : 'text-muted-foreground';
}

function Hatch({ id, color }: { id: string; color: string }) {
    return (
        <pattern
            id={id}
            width="6"
            height="6"
            patternUnits="userSpaceOnUse"
            patternTransform="rotate(45)"
        >
            <rect width="6" height="6" fill={color} fillOpacity={0.15} />
            <line
                x1="0"
                y1="0"
                x2="0"
                y2="6"
                stroke={color}
                strokeWidth="3"
                strokeOpacity={0.55}
            />
        </pattern>
    );
}

function Legend({ total }: { total: boolean }) {
    return (
        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span className="flex items-center gap-1.5">
                <span
                    className="size-2.5 rounded-sm"
                    style={{ background: INCOME }}
                />
                {__('Income')}
            </span>
            <span className="flex items-center gap-1.5">
                <span
                    className="size-2.5 rounded-sm"
                    style={{ background: EXPENSE }}
                />
                {__('Expenses')}
            </span>
            <span className="flex items-center gap-1.5">
                <span className="h-0.5 w-4" style={{ background: CLOSE }} />
                {total ? __('Total balance') : __('Closing balance')}
            </span>
            {total && (
                <span className="flex items-center gap-1.5">
                    <span className="size-2 rounded-full bg-destructive" />
                    {__('An account with a warning')}
                </span>
            )}
            <span className="flex items-center gap-1.5">
                <span
                    className="h-2.5 w-4 opacity-20"
                    style={{ background: CLOSE }}
                />
                {__('Likely range')}
            </span>
            <span className="flex items-center gap-1.5">
                <svg className="size-2.5 rounded-sm" aria-hidden="true">
                    <rect
                        width="10"
                        height="10"
                        fill="url(#forecast-hatch-legend)"
                    />
                </svg>
                {__('Forecast')}
            </span>
        </div>
    );
}

/**
 * What a month's label says under its bars: the lowest balance of the
 * account or, in the "All accounts" view, how many accounts run short.
 */
function MonthNote({
    point,
    forecast,
    shortAccounts,
}: {
    point: ForecastChartPoint;
    forecast: ForecastData | ForecastAllData;
    shortAccounts: Record<string, number> | null;
}) {
    const { formatAmountNumber } = useFormatAmount();
    const base = 'hidden text-[10px] tabular-nums sm:inline';

    if (shortAccounts !== null) {
        const count = shortAccounts[point.month] ?? 0;

        return (
            <span
                className={cn(
                    base,
                    point.status === 'locked'
                        ? 'text-muted-foreground'
                        : 'font-semibold text-destructive',
                )}
            >
                {point.status === 'locked'
                    ? __('Pro')
                    : count === 0
                      ? '\u00a0'
                      : count === 1
                        ? __('● 1 account')
                        : __('● :count accounts', { count })}
            </span>
        );
    }

    const threshold = forecast.account?.alertThreshold ?? null;

    return (
        <span
            className={cn(
                base,
                point.min === null
                    ? 'text-muted-foreground'
                    : minTone(point.min, threshold),
            )}
        >
            {point.min === null
                ? point.status === 'locked'
                    ? __('Pro')
                    : '—'
                : __('min. :amount', {
                      amount: formatAmountNumber(
                          point.min,
                          forecast.currencyCode,
                          { fractionDigits: 0 },
                      ),
                  })}
        </span>
    );
}

/**
 * Income and expenses month by month, with the closing balance and its
 * likely range: the real months first, then the forecast, hatched. Picking a
 * month (on the chart or on its label) selects its column in the plan.
 * Without Pro, the locked months are drawn as grey placeholders. In the
 * "All accounts" view the line is the total, and a red dot marks the months
 * an account runs short.
 */
export function ForecastChart({
    forecast,
    selectedMonth,
    onSelectMonth,
}: ForecastChartProps) {
    const locale = useLocale();
    const total = forecast.scope === 'all';
    const balanceAxis = total ? BALANCE_AXIS : 0;
    const points = useMemo(() => buildChartSeries(forecast), [forecast]);
    const shortAccounts = useMemo(
        () =>
            forecast.scope === 'all' ? shortAccountsByMonth(forecast) : null,
        [forecast],
    );
    const placeholder = useMemo(
        () => Math.max(0, ...points.map((point) => point.income ?? 0)) * 0.5,
        [points],
    );
    const data = points.map((point) => ({
        ...point,
        income: point.status === 'locked' ? placeholder : point.income,
        expenses: point.status === 'locked' ? placeholder : point.expenses,
    }));
    const currentMonth = points.find(
        (point) => point.status === 'current',
    )?.month;

    return (
        <Card className="gap-4" data-test="forecast-chart">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                <div className="flex flex-col gap-0.5">
                    <CardTitle>
                        {total
                            ? __('Income, expenses and total balance')
                            : __('Income, expenses and balance')}
                    </CardTitle>
                    <CardDescription>
                        {__('Pick a month to see it in the plan')}
                    </CardDescription>
                </div>
                <Legend total={total} />
            </CardHeader>
            <CardContent className="flex flex-col">
                <svg
                    width="0"
                    height="0"
                    className="absolute"
                    aria-hidden="true"
                >
                    <defs>
                        <Hatch
                            id="forecast-hatch-legend"
                            color="currentColor"
                        />
                    </defs>
                </svg>
                <ChartContainer
                    config={chartConfig}
                    className="aspect-auto h-[260px] w-full"
                >
                    <ComposedChart
                        data={data}
                        margin={{ top: 16, right: 0, bottom: 0, left: 0 }}
                        barGap={3}
                        onClick={(state) => {
                            const point = points.find(
                                (p) => p.month === state?.activeLabel,
                            );

                            if (point && isSelectable(point)) {
                                onSelectMonth(point.month);
                            }
                        }}
                    >
                        <defs>
                            <Hatch id="forecast-hatch-income" color={INCOME} />
                            <Hatch
                                id="forecast-hatch-expenses"
                                color={EXPENSE}
                            />
                        </defs>
                        <XAxis dataKey="month" hide />
                        {/* Bars must stand on zero, or their heights would mislead. */}
                        <YAxis
                            hide
                            domain={[
                                (dataMin: number) => Math.min(0, dataMin),
                                'auto',
                            ]}
                        />
                        {selectedMonth && (
                            <ReferenceArea
                                x1={selectedMonth}
                                x2={selectedMonth}
                                fill="var(--muted)"
                                fillOpacity={0.8}
                                ifOverflow="extendDomain"
                            />
                        )}
                        {currentMonth && (
                            <ReferenceLine
                                x={currentMonth}
                                stroke="var(--muted-foreground)"
                                strokeDasharray="4 4"
                                label={{
                                    value: __('Today'),
                                    position: 'insideTopLeft',
                                    fill: 'var(--foreground)',
                                    fontSize: 11,
                                    fontWeight: 600,
                                }}
                            />
                        )}
                        {total && (
                            /* The total dwarfs a month's flows: its line gets an axis of its own, as in the design. */
                            <YAxis
                                yAxisId={BALANCE_AXIS}
                                hide
                                domain={['dataMin', 'dataMax']}
                            />
                        )}
                        <ChartTooltip
                            cursor={<MonthCursor months={points} />}
                            content={({ active, label, coordinate }) => (
                                <ForecastChartTooltip
                                    active={active}
                                    label={label}
                                    coordinate={coordinate}
                                    points={points}
                                    forecast={forecast}
                                    colors={TOOLTIP_COLORS}
                                />
                            )}
                        />
                        <Area
                            yAxisId={balanceAxis}
                            dataKey="band"
                            stroke="none"
                            fill={CLOSE}
                            fillOpacity={0.12}
                            activeDot={false}
                            isAnimationActive={false}
                            connectNulls={false}
                        />
                        <Bar
                            dataKey="income"
                            radius={[3, 3, 0, 0]}
                            maxBarSize={22}
                            isAnimationActive={false}
                        >
                            {data.map((point) => (
                                <Cell
                                    key={point.month}
                                    fill={barFill(point, 'income')}
                                />
                            ))}
                        </Bar>
                        <Bar
                            dataKey="expenses"
                            radius={[3, 3, 0, 0]}
                            maxBarSize={22}
                            isAnimationActive={false}
                        >
                            {data.map((point) => (
                                <Cell
                                    key={point.month}
                                    fill={barFill(point, 'expenses')}
                                />
                            ))}
                        </Bar>
                        <Line
                            yAxisId={balanceAxis}
                            dataKey="close"
                            stroke={CLOSE}
                            strokeWidth={2.5}
                            dot={false}
                            isAnimationActive={false}
                            connectNulls={false}
                        />
                    </ComposedChart>
                </ChartContainer>
                <div className="flex border-t">
                    {points.map((point) => {
                        const selected = point.month === selectedMonth;
                        const label = monthShortLabel(point.month, locale);

                        return (
                            <button
                                key={point.month}
                                type="button"
                                disabled={!isSelectable(point)}
                                aria-pressed={
                                    isSelectable(point) ? selected : undefined
                                }
                                aria-label={__('See :month in the plan', {
                                    month: monthLongName(point.month, locale),
                                })}
                                onClick={() => onSelectMonth(point.month)}
                                className={cn(
                                    'flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-b-md py-1.5 disabled:cursor-default',
                                    selected && 'bg-muted',
                                )}
                                data-test={`forecast-chart-month-${point.month}`}
                            >
                                <span
                                    className={cn(
                                        'text-xs',
                                        selected ||
                                            point.status === 'past' ||
                                            point.status === 'current'
                                            ? 'font-semibold text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {label}
                                </span>
                                <MonthNote
                                    point={point}
                                    forecast={forecast}
                                    shortAccounts={shortAccounts}
                                />
                            </button>
                        );
                    })}
                </div>
            </CardContent>
        </Card>
    );
}
