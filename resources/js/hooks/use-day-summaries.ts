import { useAmountsInUserCurrency } from '@/hooks/use-amounts-in-user-currency';
import {
    type DaySummaryOf,
    reconcileDaySummary,
    type RowTotalAmount,
    type ServerDayTotal,
    summarizeDay,
    sumPerCurrency,
} from '@/lib/transaction-day-groups';
import { type SharedData } from '@/types';
import { type Transaction } from '@/types/transaction';
import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

/** Rows by their `transaction_date`, each day in the order its rows come. */
export function groupByDate<T extends Transaction>(
    transactions: T[],
): Map<string, T[]> {
    const byDate = new Map<string, T[]>();

    for (const transaction of transactions) {
        const day = byDate.get(transaction.transaction_date);

        if (day) {
            day.push(transaction);
        } else {
            byDate.set(transaction.transaction_date, [transaction]);
        }
    }

    return byDate;
}

/**
 * Day summaries for a list held whole in memory, which may draw only part of
 * it: each day counts and adds up every row of the list, drawn or not, and
 * converts each currency's sum once, as the server does for /transactions.
 *
 * Only the days with a header need a figure, so only those ask for a rate: a
 * long history must not turn into a provider call per day nobody looks at.
 */
export function useInMemoryDaySummaries(
    transactions: Transaction[],
    {
        drawn = transactions,
        rowTotalAmount,
    }: {
        /** The rows on screen, whose days draw a header. */
        drawn?: Transaction[];
        rowTotalAmount?: RowTotalAmount;
    } = {},
): DaySummaryOf {
    const byDate = useMemo(() => groupByDate(transactions), [transactions]);
    const daySums = useMemo(() => {
        const drawnDates = new Set(drawn.map((row) => row.transaction_date));

        return [...byDate.entries()]
            .filter(([date]) => drawnDates.has(date))
            .flatMap(([, day]) => sumPerCurrency(day, rowTotalAmount));
    }, [byDate, drawn, rowTotalAmount]);
    const amountInUserCurrency = useAmountsInUserCurrency(daySums);

    return useCallback(
        (date: string) =>
            summarizeDay(
                byDate.get(date) ?? [],
                amountInUserCurrency,
                rowTotalAmount,
            ),
        [byDate, amountInUserCurrency, rowTotalAmount],
    );
}

/**
 * Day summaries for a list the server pages through: the server counts each
 * day over every row the filters match, and the page only moves that figure
 * by what it changed locally since.
 */
export function useServerDaySummaries({
    dayTotals,
    served,
    loaded,
    rowTotalAmount,
}: {
    dayTotals: Record<string, ServerDayTotal>;
    /** Every row as the server handed it over, across the loaded pages. */
    served: Transaction[];
    /** The rows the page holds now, edits included. */
    loaded: Transaction[];
    /** What a row adds to its day under the filters the server applied. */
    rowTotalAmount?: RowTotalAmount;
}): DaySummaryOf {
    const userCurrency = usePage<SharedData>().props.auth.user.currency_code;
    const servedByDate = useMemo(() => groupByDate(served), [served]);
    const loadedByDate = useMemo(() => groupByDate(loaded), [loaded]);

    return useCallback(
        (date: string) =>
            reconcileDaySummary(
                dayTotals[date],
                servedByDate.get(date) ?? [],
                loadedByDate.get(date) ?? [],
                userCurrency,
                rowTotalAmount,
            ),
        [dayTotals, servedByDate, loadedByDate, userCurrency, rowTotalAmount],
    );
}
