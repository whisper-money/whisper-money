import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { chartRoleColors } from '@/lib/chart-colors';
import {
    buildChartSeries,
    type ForecastChartPoint,
} from '@/lib/forecast/chart-series';
import { monthLongName, monthShortLabel } from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { useMemo } from 'react';
import {
    Area,
    Bar,
    Cell,
    ComposedChart,
    Line,
    ReferenceArea,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';

const INCOME = chartRoleColors.cashflowIncomeColor;
const EXPENSE = chartRoleColors.cashflowExpenseColor;
const CLOSE = chartRoleColors.accountMainLineColor;

const chartConfig = {
    income: { label: 'Income', color: INCOME },
    expenses: { label: 'Expenses', color: EXPENSE },
    close: { label: 'Closing balance', color: CLOSE },
} satisfies ChartConfig;

interface ForecastChartProps {
    forecast: ForecastData;
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

function minTone(min: number, minimumBalance: number | null): string {
    if (min < 0) {
        return 'font-semibold text-destructive';
    }

    return min < (minimumBalance ?? 0)
        ? 'font-semibold text-warning'
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

function Legend() {
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
                {__('Closing balance')}
            </span>
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
 * Income and expenses month by month, with the closing balance and its
 * likely range: the real months first, then the forecast, hatched. Picking a
 * month (on the chart or on its label) selects its column in the plan.
 * Without Pro, the locked months are drawn as grey placeholders.
 */
export function ForecastChart({
    forecast,
    selectedMonth,
    onSelectMonth,
}: ForecastChartProps) {
    const locale = useLocale();
    const { formatAmountNumber } = useFormatAmount();
    const points = useMemo(() => buildChartSeries(forecast), [forecast]);
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
            <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-col gap-0.5">
                    <CardTitle>{__('Income, expenses and balance')}</CardTitle>
                    <CardDescription>
                        {__('Pick a month to see it in the plan')}
                    </CardDescription>
                </div>
                <Legend />
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
                        <YAxis hide domain={['auto', 'auto']} />
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
                        <Area
                            dataKey="band"
                            stroke="none"
                            fill={CLOSE}
                            fillOpacity={0.12}
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
                                <span
                                    className={cn(
                                        'text-[10px] tabular-nums',
                                        point.min === null
                                            ? 'text-muted-foreground'
                                            : minTone(
                                                  point.min,
                                                  forecast.account
                                                      .minimumBalance,
                                              ),
                                    )}
                                >
                                    {point.min === null
                                        ? __('Pro')
                                        : __('min. :amount', {
                                              amount: formatAmountNumber(
                                                  point.min,
                                                  forecast.currencyCode,
                                                  {
                                                      fractionDigits: 0,
                                                  },
                                              ),
                                          })}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </CardContent>
        </Card>
    );
}
