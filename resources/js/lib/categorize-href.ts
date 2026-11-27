import { index as transactionsIndex } from '@/routes/transactions';
import { endOfMonth, format, startOfMonth } from 'date-fns';

/**
 * The transactions list filtered to what has no category, for the places
 * that ask for that work: the uncategorized toast, the dashboard's "Finish
 * setting up" card and the forecast review's notice.
 *
 * Straight to the pile each one is asking about, and to nothing else. The
 * toast counts the month in progress, so it passes `monthInProgress` and its
 * list is that month too: an unbounded filter would open on a longer list than
 * the number the reader just clicked. The setup card counts every movement
 * brought in, so its list has no dates.
 */
export function categorizeHref({
    monthInProgress = false,
    period,
    accountIds = [],
}: {
    monthInProgress?: boolean;
    /** The days the count read, `Y-m-d`: the forecast review counts three months. */
    period?: { from: string; to: string };
    /** Only these accounts, when the count read only them. */
    accountIds?: string[];
} = {}): string {
    const today = new Date();
    const range = period
        ? { date_from: period.from, date_to: period.to }
        : monthInProgress
          ? {
                date_from: format(startOfMonth(today), 'yyyy-MM-dd'),
                date_to: format(endOfMonth(today), 'yyyy-MM-dd'),
            }
          : {};
    const accounts =
        accountIds.length > 0 ? { account_ids: accountIds.join(',') } : {};

    return transactionsIndex({
        query: { category_ids: 'uncategorized', ...range, ...accounts },
    }).url;
}
