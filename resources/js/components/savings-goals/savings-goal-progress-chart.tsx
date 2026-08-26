import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    type ChartConfig,
} from '@/components/ui/chart';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useLocale } from '@/hooks/use-locale';
import { savingsContributionAmount } from '@/types/account';
import { SavingsGoal, SavingsGoalStats } from '@/types/savings-goal';
import { Transaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import {
    addDays,
    differenceInCalendarDays,
    format,
    parseISO,
    startOfDay,
} from 'date-fns';
import { useMemo } from 'react';
import { Area, AreaChart, Line, ReferenceLine, XAxis } from 'recharts';

interface Props {
    savingsGoal: SavingsGoal;
    stats: SavingsGoalStats;
    transactions: Transaction[];
    currencyCode: string;
}

interface ChartDataPoint {
    date: string;
    saved: number | null;
    projected: number | null;
    ideal: number | null;
}

interface CustomTooltipProps {
    active?: boolean;
    payload?: Array<{ payload: ChartDataPoint }>;
    currencyCode: string;
    locale: string;
    target: number;
}

function toKey(date: Date): string {
    return format(startOfDay(date), 'yyyy-MM-dd');
}

function CustomTooltip({
    active,
    payload,
    currencyCode,
    locale,
    target,
}: CustomTooltipProps) {
    const { isPrivacyModeEnabled } = usePrivacyMode();

    if (!active || !payload || !payload.length) {
        return null;
    }

    const data = payload[0].payload;
    const mask = (value: number) => {
        const formatted = formatCurrency(value, currencyCode, locale);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
    };

    const value = data.saved ?? data.projected;

    return (
        <div className="rounded-lg border bg-background p-3 shadow-lg">
            <p className="mb-2 text-sm font-medium">
                {formatDate(data.date, 'MMM d, yyyy', locale)}
            </p>
            <div className="space-y-1 text-sm">
                <div className="flex items-center justify-between gap-8">
                    <span className="text-muted-foreground">
                        {data.saved !== null ? __('Saved:') : __('Projected:')}
                    </span>
                    <span className="font-medium">
                        {value !== null && value !== undefined
                            ? mask(value)
                            : '—'}
                    </span>
                </div>
                {data.ideal !== null && (
                    <div className="flex items-center justify-between gap-8">
                        <span className="text-muted-foreground">
                            {__('On-pace:')}
                        </span>
                        <span className="font-medium text-muted-foreground">
                            {mask(data.ideal)}
                        </span>
                    </div>
                )}
                <div className="border-t pt-1">
                    <div className="flex items-center justify-between gap-8">
                        <span className="font-medium">{__('Target:')}</span>
                        <span className="font-semibold">{mask(target)}</span>
                    </div>
                </div>
            </div>
        </div>
    );
}

export function SavingsGoalProgressChart({
    savingsGoal,
    stats,
    transactions,
    currencyCode,
}: Props) {
    const locale = useLocale();

    const chartData = useMemo<ChartDataPoint[]>(() => {
        const createdAt = startOfDay(parseISO(savingsGoal.created_at));
        const today = startOfDay(new Date());
        const target = savingsGoal.target_amount;
        // What was already put aside before the goal existed: the baseline every
        // line starts from, with linked transactions stacking on top.
        const initial = savingsGoal.initial_amount;
        const targetDate = savingsGoal.target_date
            ? startOfDay(parseISO(savingsGoal.target_date))
            : null;
        const rate = stats.rate_per_day;

        const byDate = new Map<string, number>();
        let earliest: Date | null = null;
        transactions.forEach((t) => {
            const day = startOfDay(parseISO(t.transaction_date));
            const key = toKey(day);
            byDate.set(
                key,
                (byDate.get(key) ?? 0) + savingsContributionAmount(t),
            );
            if (!earliest || day < earliest) {
                earliest = day;
            }
        });

        // Anchor the timeline on the earliest contribution when the goal was
        // created after money was already set aside, so the solid line includes
        // those contributions and joins the projection at today.
        const start = earliest && earliest < createdAt ? earliest : createdAt;

        let savedToday = initial;
        byDate.forEach((amount, key) => {
            if (parseISO(key) <= today) {
                savedToday += amount;
            }
        });

        // How far the dotted projection runs: to the target date when set,
        // otherwise until it crosses the target at the current rate (min 14 days).
        let end: Date;
        if (targetDate) {
            end = targetDate > today ? targetDate : today;
        } else {
            let horizon = 14;
            if (rate > 0 && savedToday < target) {
                horizon = Math.max(14, Math.ceil((target - savedToday) / rate));
            }
            end = addDays(today, horizon);
        }

        const totalDays = targetDate
            ? Math.max(1, differenceInCalendarDays(targetDate, start))
            : null;

        // ponytail: one point per day. A multi-year goal renders more points but
        // stays well within Recharts' comfort zone for a personal-finance app.
        const data: ChartDataPoint[] = [];
        let cumulative = initial;
        let cursor = start;

        while (cursor <= end) {
            const key = toKey(cursor);
            const isPastOrToday = cursor <= today;
            const daysFromToday = differenceInCalendarDays(cursor, today);
            const daysFromStart = differenceInCalendarDays(cursor, start);

            if (isPastOrToday) {
                cumulative += byDate.get(key) ?? 0;
            }

            data.push({
                date: key,
                saved: isPastOrToday ? cumulative : null,
                projected:
                    daysFromToday >= 0
                        ? Math.round(savedToday + rate * daysFromToday)
                        : null,
                ideal:
                    totalDays !== null
                        ? Math.min(
                              target,
                              Math.round(
                                  initial +
                                      ((target - initial) * daysFromStart) /
                                          totalDays,
                              ),
                          )
                        : null,
            });

            cursor = addDays(cursor, 1);
        }

        return data;
    }, [savingsGoal, stats, transactions]);

    const chartConfig = {
        saved: { label: __('Saved'), color: 'var(--spent)' },
        ideal: { label: __('On-pace'), color: 'var(--spent-prev)' },
    } satisfies ChartConfig;

    const todayMarker = toKey(new Date());

    return (
        <Card>
            <CardHeader>
                <CardTitle>{__('Progress')}</CardTitle>
                <CardDescription>
                    {__('How your savings are tracking toward the target')}
                </CardDescription>
            </CardHeader>
            <CardContent className="px-6 pt-0">
                <ChartContainer
                    config={chartConfig}
                    className="h-[300px] w-full"
                >
                    <AreaChart
                        className="overflow-hidden rounded"
                        data={chartData}
                        margin={{ top: 12, right: 0, bottom: 0, left: 0 }}
                    >
                        <defs>
                            <linearGradient
                                id="fillSaved"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stopColor="var(--color-saved)"
                                    stopOpacity={0.8}
                                />
                                <stop
                                    offset="50%"
                                    stopColor="var(--color-saved)"
                                    stopOpacity={0.4}
                                />
                                <stop
                                    offset="100%"
                                    stopColor="var(--color-saved)"
                                    stopOpacity={0.05}
                                />
                            </linearGradient>
                        </defs>
                        <XAxis
                            dataKey="date"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            tickFormatter={(value) =>
                                formatDate(value, 'MMM d', locale)
                            }
                        />

                        <ChartTooltip
                            content={
                                <CustomTooltip
                                    currencyCode={currencyCode}
                                    locale={locale}
                                    target={savingsGoal.target_amount}
                                />
                            }
                        />

                        <ReferenceLine
                            y={savingsGoal.target_amount}
                            stroke="var(--color-muted-foreground)"
                            strokeWidth={1}
                            strokeDasharray="4 4"
                            ifOverflow="extendDomain"
                            label={{
                                value: __('Target'),
                                position: 'insideTopRight',
                                fontSize: 12,
                                fill: 'var(--color-muted-foreground)',
                            }}
                        />

                        <ReferenceLine
                            x={todayMarker}
                            stroke="var(--color-foreground)"
                            strokeWidth={1}
                            strokeDasharray="4 4"
                            label={{
                                value: __('Today'),
                                position: 'top',
                                fontSize: 12,
                                fill: 'var(--color-muted-foreground)',
                            }}
                        />

                        <Area
                            dataKey="saved"
                            type="monotone"
                            fill="url(#fillSaved)"
                            stroke="var(--color-saved)"
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 6 }}
                            fillOpacity={1}
                            connectNulls={false}
                        />

                        <Line
                            dataKey="projected"
                            type="monotone"
                            stroke="var(--color-saved)"
                            strokeWidth={2}
                            strokeDasharray="6 4"
                            dot={false}
                            activeDot={{ r: 4 }}
                            connectNulls={false}
                        />

                        <Line
                            dataKey="ideal"
                            type="monotone"
                            stroke="var(--color-ideal)"
                            strokeWidth={2}
                            strokeDasharray="2 4"
                            dot={false}
                            activeDot={false}
                            connectNulls={false}
                        />
                    </AreaChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}
