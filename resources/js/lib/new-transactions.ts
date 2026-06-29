import { type Transaction } from '@/types/transaction';

const LAST_VISIT_KEY = 'transactions-last-visit';

export function loadLastVisit(): string | null {
    if (typeof window === 'undefined') return null;

    try {
        return localStorage.getItem(LAST_VISIT_KEY);
    } catch (error) {
        console.error('Failed to load last visit:', error);
        return null;
    }
}

export function saveLastVisit(value: string): void {
    if (typeof window === 'undefined') return;

    try {
        localStorage.setItem(LAST_VISIT_KEY, value);
    } catch (error) {
        console.error('Failed to save last visit:', error);
    }
}

/**
 * The most recent `created_at` (insertion time) across the given transactions,
 * or null when there are none. Compared as timestamps so it does not rely on a
 * particular string format.
 */
export function newestCreatedAt(
    transactions: Pick<Transaction, 'created_at'>[],
): string | null {
    let newest: string | null = null;
    let newestMs = -Infinity;

    for (const transaction of transactions) {
        const ms = Date.parse(transaction.created_at);
        if (!Number.isNaN(ms) && ms > newestMs) {
            newest = transaction.created_at;
            newestMs = ms;
        }
    }

    return newest;
}

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
