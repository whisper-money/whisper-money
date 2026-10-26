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

export type DayTotal =
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
): DayTotal {
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
