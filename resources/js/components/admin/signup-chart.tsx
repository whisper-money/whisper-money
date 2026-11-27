import HeadingSmall from '@/components/heading-small';
import { Card, CardContent } from '@/components/ui/card';
import {
    type ChartConfig,
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { useLocale } from '@/hooks/use-locale';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { parseISO } from 'date-fns';
import { Bar, ComposedChart, Line, XAxis, YAxis } from 'recharts';

export type SignupChartPoint = {
    /** A UTC day, `yyyy-MM-dd`. */
    date: string;
    signups: number;
    /** Distinct users active in the 30 days up to and including `date`. */
    active_users: number;
};

function chartConfig(): ChartConfig {
    return {
        signups: { label: __('Sign-ups'), color: 'var(--color-chart-1)' },
        active_users: {
            label: __('Active in the last 30 days'),
            color: 'var(--color-chart-3)',
        },
    };
}

/**
 * Sign-ups per day as bars, and the trailing 30-day active users as a line on
 * its own axis: the two differ by orders of magnitude.
 */
export function SignupChart({ points }: { points: SignupChartPoint[] }) {
    const locale = useLocale();
    const dayLabel = (date: string, pattern: string) =>
        formatDate(parseISO(date), pattern, locale);

    return (
        <section className="space-y-4">
            <HeadingSmall
                title={__('Sign-ups per day')}
                description={__('Last 30 days, in UTC.')}
            />
            <Card>
                <CardContent>
                    <ChartContainer
                        config={chartConfig()}
                        className="aspect-auto h-64 w-full"
                    >
                        <ComposedChart data={points}>
                            <XAxis
                                dataKey="date"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                minTickGap={24}
                                tickFormatter={(date: string) =>
                                    dayLabel(date, 'MMM d')
                                }
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
                                    <ChartTooltipContent
                                        hideTotal
                                        labelFormatter={(_, payload) =>
                                            payload[0]?.payload?.date
                                                ? dayLabel(
                                                      String(
                                                          payload[0].payload
                                                              .date,
                                                      ),
                                                      'MMM d, yyyy',
                                                  )
                                                : null
                                        }
                                        valueFormatter={(value) =>
                                            value.toLocaleString()
                                        }
                                    />
                                }
                            />
                            <ChartLegend content={<ChartLegendContent />} />
                            <Bar
                                yAxisId="signups"
                                dataKey="signups"
                                fill="var(--color-signups)"
                                radius={[3, 3, 0, 0]}
                            />
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
