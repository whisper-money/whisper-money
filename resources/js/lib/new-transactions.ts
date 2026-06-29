import { type Transaction } from '@/types/transaction';

/**
 * Index of the first transaction that was already present at the given visit
 * timestamp, assuming the list is sorted newest-first. Transactions above this
 * index arrived since the last visit and sit above the "Last visit" divider.
 *
 * Returns -1 when there is no meaningful divider to draw: no recorded visit, an
 * unparseable timestamp, nothing new (the first row is already seen), or every
 * loaded row is new (the boundary lives in a not-yet-loaded page).
 */
export function firstSeenTransactionIndex(
    transactions: Pick<Transaction, 'created_at'>[],
    lastVisit: string | null,
): number {
    if (!lastVisit) {
        return -1;
    }

    const lastVisitMs = Date.parse(lastVisit);
    if (Number.isNaN(lastVisitMs)) {
        return -1;
    }

    const firstSeen = transactions.findIndex(
        (transaction) => Date.parse(transaction.created_at) <= lastVisitMs,
    );

    return firstSeen > 0 ? firstSeen : -1;
}
