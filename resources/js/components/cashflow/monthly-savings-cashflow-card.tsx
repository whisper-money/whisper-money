import { monthlySavings } from '@/actions/App/Http/Controllers/Api/CashflowAnalyticsController';
import { MonthStatusLegend } from '@/components/savings-goals/monthly/month-status';
import {
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

export interface MonthlySavingsTotals extends MonthlySavingsBar {
    difference: number;
    met: number;
    total: number;
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
 * their targets, with the six months up to it. Hidden when no monthly goal
 * had that month.
 */
export function MonthlySavingsCashflowCard({
    month,
    net,
    currencyCode,
}: Props) {
    const locale = useLocale();
    const key = monthKey(month);
    const [totals, setTotals] = useState<MonthlySavingsTotals | null>(null);

    useEffect(() => {
        let current = true;

        fetchJson<{ data: MonthlySavingsTotals | null }>(
            monthlySavings.url({ query: { month: key } }),
        )
            .then((response) => current && setTotals(response.data))
            // The card is a side note on this page; a failed load just
            // leaves it out rather than taking the page's error banner.
            .catch(() => current && setTotals(null));

        return () => {
            current = false;
        };
    }, [key]);

    if (totals === null) {
        return null;
    }

    const share =
        net !== null && net > 0 ? Math.round((totals.saved / net) * 100) : null;

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
                    <SavedOfTarget
                        saved={totals.saved}
                        target={totals.target}
                        currencyCode={currencyCode}
                    />
                    <span className="text-sm">
                        <SignedAmount
                            amount={totals.difference}
                            currencyCode={currencyCode}
                        />{' '}
                        <span className="text-muted-foreground">
                            {__('against the plan')}
                        </span>
                    </span>
                    <div className="flex flex-col gap-1.5 border-t pt-3 text-sm text-muted-foreground">
                        {share !== null && (
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
                            {__(':met of :total goals met', {
                                met: totals.met,
                                total: totals.total,
                            })}
                        </span>
                    </div>
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-3">
                    <div className="flex flex-wrap justify-end gap-4">
                        <MonthStatusLegend statuses={['met', 'missed']} />
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
