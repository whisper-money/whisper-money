import { EditCreditCardDetailDialog } from '@/components/accounts/edit-credit-card-detail-dialog';
import { AreaGradient } from '@/components/charts';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    ChartTooltipContent,
} from '@/components/ui/chart';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import type {
    CreditCardDetail,
    CreditCardUsage,
    CurrencyCode,
} from '@/types/account';
import { formatCurrency } from '@/utils/currency';
import {
    formatDateMedium,
    formatDayFromDate,
    formatMonthYear,
    toLocalDate,
} from '@/utils/date';
import { __ } from '@/utils/i18n';
import { eachDayOfInterval, format } from 'date-fns';
import { Gauge } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import { Area, AreaChart, ReferenceLine, XAxis, YAxis } from 'recharts';

interface CreditCardUsageChartProps {
    accountId: string;
    currencyCode: CurrencyCode;
    usage: CreditCardUsage;
    detail: CreditCardDetail | null;
}

export interface UsageChartPoint {
    date: string;
    /** Null on the days after today, so the line stops where the data does. */
    used: number | null;
}

/**
 * One point per day of the usage window, so the axis spans all of it while
 * the line only runs through the days the server has figures for.
 */
export function usageChartData(usage: CreditCardUsage): UsageChartPoint[] {
    const usedOn = new Map(usage.daily.map((day) => [day.date, day.used]));

    return eachDayOfInterval({
        start: toLocalDate(usage.period_from),
        end: toLocalDate(usage.period_to),
    }).map((day) => {
        const date = format(day, 'yyyy-MM-dd');

        return { date, used: usedOn.get(date) ?? null };
    });
}

interface UsagePeriod {
    /** The span `used` covers, as dates or as a month. */
    label: string;
    /** What `used` counts over that span, for the footnote. */
    explanation: string;
}

/**
 * The span `used` covers, named the way the user thinks of it: the dates of
 * the statements still to be charged when the card has dates to go by (it can
 * take a closed statement and the open cycle), the calendar month otherwise.
 */
function usagePeriod(
    usage: CreditCardUsage,
    detail: CreditCardDetail | null,
    locale: string,
): UsagePeriod {
    if (detail?.statement_closing_date && detail.payment_due_date) {
        return {
            label: __('From :from to :to', {
                from: formatDateMedium(usage.period_from, locale),
                to: formatDateMedium(usage.period_to, locale),
            }),
            explanation: __(
                'Used counts the purchases on this card that are still to be charged: the statement not paid yet and the open cycle. Payments to the card are not subtracted.',
            ),
        };
    }

    return {
        label: formatMonthYear(toLocalDate(usage.period_from), locale),
        explanation: __(
            'Used counts the purchases on this card this month. Payments to the card are not subtracted.',
        ),
    };
}

/** Over the limit, the figures that say so turn red. */
const OVER_LIMIT_TEXT = 'text-red-600 dark:text-red-400';

/**
 * Takes the place of the balance chart on a credit card: how much of the
 * limit is in use, how much is left and how the spending built up across the
 * window still to be charged. Without a limit it shows what was used and
 * asks for the limit.
 */
export function CreditCardUsageChart({
    accountId,
    currencyCode,
    usage,
    detail,
}: CreditCardUsageChartProps) {
    const locale = useLocale();
    const [dialogOpen, setDialogOpen] = useState(false);
    const period = usagePeriod(usage, detail, locale);

    return (
        <Card>
            {usage.limit === null || usage.available === null ? (
                <MissingLimit
                    usage={usage}
                    currencyCode={currencyCode}
                    period={period}
                    onSetLimit={() => setDialogOpen(true)}
                />
            ) : (
                <LimitUsage
                    usage={usage}
                    limit={usage.limit}
                    available={usage.available}
                    currencyCode={currencyCode}
                    period={period}
                />
            )}

            <EditCreditCardDetailDialog
                accountId={accountId}
                currencyCode={currencyCode}
                detail={detail}
                open={dialogOpen}
                onOpenChange={setDialogOpen}
            />
        </Card>
    );
}

/** The footnote under the figures, so "used" is never read as a balance. */
function PeriodExplanation({ period }: { period: UsagePeriod }) {
    return (
        <p className="text-xs text-muted-foreground">{period.explanation}</p>
    );
}

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd>{children}</dd>
        </div>
    );
}

function MissingLimit({
    usage,
    currencyCode,
    period,
    onSetLimit,
}: {
    usage: CreditCardUsage;
    currencyCode: CurrencyCode;
    period: UsagePeriod;
    onSetLimit: () => void;
}) {
    return (
        <>
            <CardHeader>
                <CardTitle>{__('Credit used')}</CardTitle>
                <CardDescription>{period.label}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                <AmountDisplay
                    amountInCents={usage.used}
                    currencyCode={currencyCode}
                    size="4xl"
                    weight="semibold"
                />
                <div className="flex flex-col items-start gap-3 rounded-md border border-dashed border-border p-4">
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Add the credit limit of this card to see how much of it you have used and how much is still available.',
                        )}
                    </p>
                    <Button variant="outline" size="sm" onClick={onSetLimit}>
                        <Gauge className="h-4 w-4" />
                        {__('Set credit limit')}
                    </Button>
                </div>
                <PeriodExplanation period={period} />
            </CardContent>
        </>
    );
}

function LimitUsage({
    usage,
    limit,
    available,
    currencyCode,
    period,
}: {
    usage: CreditCardUsage;
    limit: number;
    available: number;
    currencyCode: CurrencyCode;
    period: UsagePeriod;
}) {
    const isOverLimit = available < 0;
    // Refunds can outweigh purchases and leave `used` below zero.
    const usedPercent = Math.max(0, (usage.used / limit) * 100);

    return (
        <>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    {__('Credit limit')}
                    {isOverLimit && (
                        <Badge variant="destructive">{__('Over limit')}</Badge>
                    )}
                </CardTitle>
                <CardDescription>{period.label}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <Figure label={__('Used')}>
                        <AmountDisplay
                            amountInCents={usage.used}
                            currencyCode={currencyCode}
                            size="2xl"
                            weight="semibold"
                        />
                    </Figure>
                    <Figure label={__('Available')}>
                        <span
                            className={
                                isOverLimit ? OVER_LIMIT_TEXT : undefined
                            }
                        >
                            <AmountDisplay
                                amountInCents={available}
                                currencyCode={currencyCode}
                                size="2xl"
                                weight="semibold"
                            />
                        </span>
                    </Figure>
                    <Figure label={__('Limit')}>
                        <AmountDisplay
                            amountInCents={limit}
                            currencyCode={currencyCode}
                            size="2xl"
                            weight="semibold"
                        />
                    </Figure>
                </dl>

                <div className="flex flex-col gap-2">
                    <Progress
                        // Radix reads a value past `max` as no value at all.
                        value={Math.min(100, usedPercent)}
                        className="h-2"
                        aria-label={__('Credit used')}
                        indicatorClassName={
                            isOverLimit ? 'bg-red-600 dark:bg-red-400' : ''
                        }
                        indicatorColor={
                            isOverLimit ? undefined : 'var(--color-chart-2)'
                        }
                    />
                    <span
                        className={`flex flex-wrap items-baseline gap-1 text-sm ${isOverLimit ? OVER_LIMIT_TEXT : 'text-muted-foreground'}`}
                    >
                        {__(':percent% of the limit used', {
                            percent: Math.round(usedPercent),
                        })}
                        {isOverLimit && (
                            <>
                                <span aria-hidden>·</span>
                                <span>{__('Over the limit by')}</span>
                                <AmountDisplay
                                    amountInCents={-available}
                                    currencyCode={currencyCode}
                                />
                            </>
                        )}
                    </span>
                </div>

                <UsageChart
                    usage={usage}
                    limit={limit}
                    currencyCode={currencyCode}
                />
                <PeriodExplanation period={period} />
            </CardContent>
        </>
    );
}

function UsageChart({
    usage,
    limit,
    currencyCode,
}: {
    usage: CreditCardUsage;
    limit: number;
    currencyCode: CurrencyCode;
}) {
    const locale = useLocale();
    const data = useMemo(() => usageChartData(usage), [usage]);

    if (usage.daily.length === 0) {
        return (
            <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                {__('No spending recorded in this period yet')}
            </div>
        );
    }

    const chartConfig: ChartConfig = {
        used: { label: __('Used'), color: 'var(--color-chart-2)' },
    };

    return (
        <ChartContainer config={chartConfig} className="h-[240px] w-full">
            <AreaChart accessibilityLayer data={data}>
                <defs>
                    <AreaGradient id="fillUsed" color="var(--color-chart-2)" />
                </defs>
                <XAxis
                    dataKey="date"
                    tickLine={false}
                    tickMargin={10}
                    axisLine={false}
                    minTickGap={24}
                    tickFormatter={(date: string) =>
                        formatDayFromDate(date, locale)
                    }
                />
                <YAxis hide domain={[0, 'auto']} />
                <ChartTooltip
                    content={
                        <ChartTooltipContent
                            labelFormatter={(date) =>
                                formatDateMedium(String(date), locale)
                            }
                            valueFormatter={(value: number) =>
                                formatCurrency(value, currencyCode, locale)
                            }
                        />
                    }
                />
                <ReferenceLine
                    y={limit}
                    ifOverflow="extendDomain"
                    stroke="var(--color-muted-foreground)"
                    strokeDasharray="4 4"
                    label={{
                        value: __('Limit'),
                        position: 'insideTopLeft',
                        fontSize: 12,
                        fill: 'var(--color-muted-foreground)',
                    }}
                />
                <Area
                    dataKey="used"
                    type="monotone"
                    fill="url(#fillUsed)"
                    stroke="var(--color-chart-2)"
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 5 }}
                    fillOpacity={1}
                />
            </AreaChart>
        </ChartContainer>
    );
}
