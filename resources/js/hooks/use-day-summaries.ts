import { useAmountsInUserCurrency } from '@/hooks/use-amounts-in-user-currency';
import {
    type DaySummaryOf,
    reconcileDaySummary,
    type ServerDayTotal,
    summarizeDay,
} from '@/lib/transaction-day-groups';
import { type SharedData } from '@/types';
import { type Transaction } from '@/types/transaction';
import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

function groupByDate<T extends Transaction>(
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
 * it: each day counts and adds up every row of the list, drawn or not.
 */
export function useInMemoryDaySummaries(
    transactions: Transaction[],
): DaySummaryOf {
    const amountInUserCurrency = useAmountsInUserCurrency(transactions);
    const byDate = useMemo(() => groupByDate(transactions), [transactions]);

    return useCallback(
        (date: string) =>
            summarizeDay(byDate.get(date) ?? [], amountInUserCurrency),
        [byDate, amountInUserCurrency],
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
}: {
    dayTotals: Record<string, ServerDayTotal>;
    /** Every row as the server handed it over, across the loaded pages. */
    served: Transaction[];
    /** The rows the page holds now, edits included. */
    loaded: Transaction[];
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
            ),
        [dayTotals, servedByDate, loadedByDate, userCurrency],
    );
}
