import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import { listOf, monthLongName } from '@/lib/forecast/format';
import { type ForecastRestOfMonthData } from '@/types/forecast-entry';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';

/** Concrete lines listed one by one; the rest are summed in one row. */
const MAX_LINES = 4;

/** Estimated categories named in the estimate row before "N more". */
const MAX_ESTIMATE_NAMES = 2;

interface Row {
    key: string;
    label: string;
    amount: number;
}

function linesRows(
    lines: ForecastRestOfMonthData['lines'],
    locale: string,
): Row[] {
    const shown = lines.length > MAX_LINES ? MAX_LINES - 1 : lines.length;
    const rows: Row[] = lines.slice(0, shown).map((line, index) => ({
        key: `line-${index}`,
        label: `${line.name} (${formatDate(line.date, 'd MMM', locale)})`,
        amount: line.amount,
    }));
    const rest = lines.slice(shown);

    if (rest.length > 0) {
        rows.push({
            key: 'line-rest',
            label: __(':count more planned', { count: rest.length }),
            amount: rest.reduce((sum, line) => sum + line.amount, 0),
        });
    }

    return rows;
}

/** One row for the estimated income and one for the estimated spend. */
function estimateRows(
    estimates: ForecastRestOfMonthData['estimates'],
    locale: string,
): Row[] {
    return [
        estimateRow(
            'estimates-income',
            estimates.filter((estimate) => estimate.kind === 'income'),
            __('Other income'),
            locale,
        ),
        estimateRow(
            'estimates',
            estimates.filter((estimate) => estimate.kind === 'expense'),
            __('Other spending'),
            locale,
        ),
    ].filter((row): row is Row => row !== null);
}

function estimateRow(
    key: string,
    estimates: ForecastRestOfMonthData['estimates'],
    otherName: string,
    locale: string,
): Row | null {
    if (estimates.length === 0) {
        return null;
    }

    const names = estimates
        .slice(0, MAX_ESTIMATE_NAMES)
        .map((estimate) => estimate.name ?? otherName);
    const more = estimates.length - names.length;

    return {
        key,
        label: listOf(
            more > 0 ? [...names, __(':count more', { count: more })] : names,
            locale,
        ),
        amount: estimates.reduce((sum, estimate) => sum + estimate.amount, 0),
    };
}

/**
 * Cashflow's look ahead in the month in progress: what the forecast still
 * expects to come in and go out before the month ends, and the balance it
 * closes on. The page draws it only while that month is on screen.
 */
export function ForecastRestOfMonthCard({
    rest,
}: {
    rest: ForecastRestOfMonthData;
}) {
    const locale = useLocale();
    const rows = [
        ...linesRows(rest.lines, locale),
        ...estimateRows(rest.estimates, locale),
    ];

    return (
        <Card data-test="forecast-rest-of-month">
            <CardHeader className="flex flex-row items-baseline justify-between gap-2">
                <CardTitle className="text-base">
                    {__('What is left of :month', {
                        month: monthLongName(rest.month, locale),
                    })}
                </CardTitle>
                <span className="text-xs text-muted-foreground">
                    {__('forecasted')}
                </span>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2 text-sm tabular-nums">
                    {rows.map((row) => (
                        <div key={row.key} className="contents">
                            <dt className="truncate text-muted-foreground">
                                {row.label}
                            </dt>
                            <dd className="text-right">
                                <AmountDisplay
                                    amountInCents={row.amount}
                                    currencyCode={rest.currencyCode}
                                    showSign
                                    className={
                                        row.amount > 0 ? 'text-success' : ''
                                    }
                                />
                            </dd>
                        </div>
                    ))}
                    <dt className="font-semibold">
                        {__('Balance at the end of the month')}
                    </dt>
                    <dd className="text-right font-semibold">
                        <AmountDisplay
                            amountInCents={rest.endBalance}
                            currencyCode={rest.currencyCode}
                        />
                    </dd>
                </dl>
                <Button variant="outline" className="self-start" asChild>
                    <Link href={forecastIndex().url}>
                        {__('See the next 12 months')}
                    </Link>
                </Button>
            </CardContent>
        </Card>
    );
}
