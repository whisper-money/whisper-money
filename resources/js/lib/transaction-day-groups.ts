import { type Transaction } from '@/types/transaction';
import { formatDate, toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { type SortingState } from '@tanstack/react-table';
import { isToday, isValid, isYesterday } from 'date-fns';

/**
 * The table groups rows under a header per day only while it is sorted by
 * date: sorted by anything else, the rows of one day are scattered and the
 * headers would repeat.
 */
export function isSortedByDate(sorting: SortingState): boolean {
    return sorting[0]?.id === 'transaction_date';
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * What a day header says about its day: "Today" and the full date beside it,
 * or the weekday and the date without it. The year only shows outside the
 * current one.
 */
export function formatDayHeading(
    date: string,
    locale: string,
): { name: string; date: string } {
    const day = toLocalDate(date);

    if (!isValid(day)) {
        return { name: date, date: '' };
    }

    const year = day.getFullYear() === new Date().getFullYear() ? '' : ' yyyy';
    const relativeName = isToday(day)
        ? __('Today')
        : isYesterday(day)
          ? __('Yesterday')
          : null;

    if (relativeName) {
        return {
            name: relativeName,
            date: formatDate(day, `EEEE, d MMMM${year}`, locale),
        };
    }

    return {
        name: capitalize(formatDate(day, 'EEEE', locale)),
        date: formatDate(day, `d MMMM${year}`, locale),
    };
}

/**
 * The short line above a day of the compact list: "Today · 26 Oct", or the
 * weekday and the date. The year only shows outside the current one.
 */
export function formatDayBand(date: string, locale: string): string {
    const day = toLocalDate(date);

    if (!isValid(day)) {
        return date;
    }

    const year = day.getFullYear() === new Date().getFullYear() ? '' : ' yyyy';
    const name = isToday(day)
        ? __('Today')
        : isYesterday(day)
          ? __('Yesterday')
          : formatDate(day, 'EEEE', locale);

    return `${name} · ${formatDate(day, `d MMM${year}`, locale)}`;
}

export function formatTransactionCount(count: number): string {
    return count === 1
        ? __('1 transaction')
        : __(':count transactions', { count });
}

/**
 * One transaction's amount in the user's currency: a number once known, null
 * when its day holds no rate for its currency, undefined while it is asked for.
 */
export type AmountInUserCurrency = (
    transaction: Transaction,
) => number | null | undefined;

export type DayTotalState =
    | { status: 'ready'; amount: number }
    | { status: 'pending' }
    | { status: 'missing' };

/**
 * A day's transactions added up in the user's currency. One amount without a
 * rate leaves the whole total out: a sum that silently skipped it, or added it
 * unconverted, would be a wrong figure printed as a right one.
 */
export function sumDayTotal(
    transactions: Transaction[],
    amountInUserCurrency: AmountInUserCurrency,
): DayTotalState {
    let amount = 0;
    let isPending = false;

    for (const transaction of transactions) {
        const converted = amountInUserCurrency(transaction);

        if (converted === null) {
            return { status: 'missing' };
        }

        if (converted === undefined) {
            isPending = true;
            continue;
        }

        amount += converted;
    }

    return isPending ? { status: 'pending' } : { status: 'ready', amount };
}

/** What a day header says about its day: how many rows and their total. */
export interface DaySummary {
    count: number;
    total: DayTotalState;
}

/** A day as the server counted it, its total null when a rate was missing. */
export interface ServerDayTotal {
    count: number;
    total: number | null;
}

/** The summary of each day, looked up by its `Y-m-d` date. */
export type DaySummaryOf = (date: string) => DaySummary;

/** Every transaction of a day, all of them in memory. */
export function summarizeDay(
    transactions: Transaction[],
    amountInUserCurrency: AmountInUserCurrency,
): DaySummary {
    return {
        count: transactions.length,
        total: sumDayTotal(transactions, amountInUserCurrency),
    };
}

function rowFingerprint(transaction: Transaction): string {
    return `${transaction.id}|${transaction.currency_code}|${transaction.amount}`;
}

/**
 * The server's figure for a day, moved by whatever the page changed since it
 * served the day's rows: a deleted row, an edited amount, a row moved in from
 * another day. Rows the page has not touched cancel out, so only the changed
 * ones need a rate; one in another currency leaves the total out, as a missing
 * rate does.
 *
 * @param served The day's rows as the server handed them over.
 * @param loaded The day's rows as the page holds them now.
 */
export function reconcileDaySummary(
    server: ServerDayTotal | undefined,
    served: Transaction[],
    loaded: Transaction[],
    userCurrency: string,
): DaySummary {
    const count = (server?.count ?? 0) - served.length + loaded.length;
    const servedKeys = new Set(served.map(rowFingerprint));
    const loadedKeys = new Set(loaded.map(rowFingerprint));
    const removed = served.filter(
        (row) => !loadedKeys.has(rowFingerprint(row)),
    );
    const added = loaded.filter((row) => !servedKeys.has(rowFingerprint(row)));

    const isInUserCurrency = (row: Transaction) =>
        row.currency_code.toUpperCase() === userCurrency.toUpperCase();

    if (
        server?.total === null ||
        ![...removed, ...added].every(isInUserCurrency)
    ) {
        return { count, total: { status: 'missing' } };
    }

    const amount =
        (server?.total ?? 0) +
        added.reduce((sum, row) => sum + row.amount, 0) -
        removed.reduce((sum, row) => sum + row.amount, 0);

    return { count, total: { status: 'ready', amount } };
}
