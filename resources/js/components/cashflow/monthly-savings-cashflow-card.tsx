import { monthlySavings } from '@/actions/App/Http/Controllers/Api/CashflowAnalyticsController';
import { MonthStatusLegend } from '@/components/savings-goals/monthly/month-status';
import {
    AmountToGo,
    SavedOfTarget,
    SignedAmount,
} from '@/components/savings-goals/monthly/monthly-goal-figures';
import {
    MonthlySavingsBar,
    MonthlySavingsBarChart,
    TargetLineLegend,
} from '@/components/savings-goals/monthly/monthly-savings-bar-chart';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { fetchJson } from '@/lib/fetch-json';
import { monthDate, monthKey } from '@/lib/monthly-savings';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Repeat } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface MonthlySavingsTotals extends Omit<
    MonthlySavingsBar,
    'status'
> {
    /** Null for a month no goal was judged in; the history still comes. */
    status: MonthlySavingsBar['status'] | null;
    difference: number;
    met: number;
    total: number;
    /** Every goal follows an income that has not come in yet. */
    target_pending: boolean;
    history: MonthlySavingsBar[];
}

interface Props {
    /** Any day of the month on screen. */
    month: Date;
    /** The month's net cashflow, to say how much of it went to the goals. */
    net: number | null;
    currencyCode: string;
}

/**
 * Saved towards every monthly goal in the month on screen against the sum of
 * their targets, with the six months up to it. A month no goal was judged in
 * still shows the months before it; the card is hidden only when none of
 * those months had a goal.
 */
export function MonthlySavingsCashflowCard({
    month,
    net,
    currencyCode,
}: Props) {
    const locale = useLocale();
    const key = monthKey(month);
    // Kept with the month it answers for, so stepping to another month never
    // shows the last one's figures against the new month's net while it loads.
    const [loaded, setLoaded] = useState<{
        key: string;
        totals: MonthlySavingsTotals | null;
    } | null>(null);
    const totals = loaded?.key === key ? loaded.totals : null;

    useEffect(() => {
        let current = true;

        fetchJson<{ data: MonthlySavingsTotals | null }>(
            monthlySavings.url({ query: { month: key } }),
        )
            .then(
                (response) =>
                    current && setLoaded({ key, totals: response.data }),
            )
            // The card is a side note on this page; a failed load just
            // leaves it out rather than taking the page's error banner.
            .catch(() => current && setLoaded({ key, totals: null }));

        return () => {
            current = false;
        };
    }, [key]);

    if (totals === null) {
        return null;
    }

    const inProgress = totals.status === 'in_progress';
    const empty = totals.total === 0;
    // Tagged contributions are not a slice of net cashflow by construction (a
    // transfer in, a withdrawal tagged by hand), so a share outside 0-100 says
    // nothing true and is left out.
    const share =
        net !== null && net > 0 ? Math.round((totals.saved / net) * 100) : null;
    const showShare = share !== null && share >= 0 && share <= 100;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Repeat className="size-4" />
                    {__('Monthly savings · :month', {
                        month: formatDate(
                            monthDate(totals.month),
                            'MMMM',
                            locale,
                        ),
                    })}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-6 lg:flex-row">
                <div className="flex flex-col gap-3 lg:w-60 lg:shrink-0">
                    {empty ? (
                        <span className="text-sm text-muted-foreground">
                            {__('No monthly goal counts in this month.')}
                        </span>
                    ) : (
                        <>
                            <SavedOfTarget
                                saved={totals.saved}
                                target={totals.target}
                                currencyCode={currencyCode}
                            />
                            <span className="text-sm">
                                {inProgress && totals.target_pending ? (
                                    <span className="text-muted-foreground">
                                        {__('In progress')} ·{' '}
                                        {__(
                                            'the target grows as income comes in',
                                        )}
                                    </span>
                                ) : inProgress ? (
                                    <span className="text-muted-foreground">
                                        {totals.difference < 0 ? (
                                            <AmountToGo
                                                amount={-totals.difference}
                                                currencyCode={currencyCode}
                                            />
                                        ) : (
                                            __('Target met')
                                        )}
                                    </span>
                                ) : (
                                    <>
                                        <SignedAmount
                                            amount={totals.difference}
                                            currencyCode={currencyCode}
                                        />{' '}
                                        <span className="text-muted-foreground">
                                            {__('against the plan')}
                                        </span>
                                    </>
                                )}
                            </span>
                            <div className="flex flex-col gap-1.5 border-t pt-3 text-sm text-muted-foreground">
                                {showShare && (
                                    <span>
                                        {__(
                                            ":percent% of the month's net cashflow went to monthly goals",
                                            {
                                                percent: share,
                                            },
                                        )}
                                    </span>
                                )}
                                <span>
                                    {__(
                                        inProgress
                                            ? ':met of :total goals reached so far'
                                            : ':met of :total goals met',
                                        {
                                            met: totals.met,
                                            total: totals.total,
                                        },
                                    )}
                                </span>
                            </div>
                        </>
                    )}
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-3">
                    <div className="flex flex-wrap justify-end gap-4">
                        <MonthStatusLegend
                            statuses={
                                inProgress
                                    ? ['met', 'missed', 'in_progress']
                                    : ['met', 'missed']
                            }
                        />
                        <TargetLineLegend label={__('Sum of targets')} />
                    </div>
                    <MonthlySavingsBarChart
                        bars={totals.history}
                        currencyCode={currencyCode}
                        height={160}
                    />
                </div>
            </CardContent>
        </Card>
    );
}
