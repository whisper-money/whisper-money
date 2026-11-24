import { index as transactionsIndex } from '@/routes/transactions';
import { endOfMonth, format, startOfMonth } from 'date-fns';

/**
 * The transactions list filtered to what has no category, for the two places
 * that ask for that work: the uncategorized toast and the dashboard's "Finish
 * setting up" card.
 *
 * Straight to the pile each one is asking about, and to nothing else. The
 * toast counts the month in progress, so it passes `monthInProgress` and its
 * list is that month too: an unbounded filter would open on a longer list than
 * the number the reader just clicked. The setup card counts every movement
 * brought in, so its list has no dates.
 */
export function categorizeHref({
    monthInProgress = false,
}: { monthInProgress?: boolean } = {}): string {
    const today = new Date();
    const monthRange = monthInProgress
        ? {
              date_from: format(startOfMonth(today), 'yyyy-MM-dd'),
              date_to: format(endOfMonth(today), 'yyyy-MM-dd'),
          }
        : {};

    return transactionsIndex({
        query: { category_ids: 'uncategorized', ...monthRange },
    }).url;
}
