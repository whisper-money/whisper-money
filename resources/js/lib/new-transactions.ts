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
 * Whether a transaction was inserted after the given visit timestamp, i.e. it
 * arrived since the user last opened the list. Compared per-row (not as a
 * positional boundary) because the list is sorted by transaction_date, which
 * does not correlate with created_at — a newly synced row can have an old date
 * and sit anywhere in the list.
 */
export function isNewSince(
    transaction: Pick<Transaction, 'created_at'>,
    lastVisit: string | null,
): boolean {
    if (!lastVisit) {
        return false;
    }

    const lastVisitMs = Date.parse(lastVisit);
    if (Number.isNaN(lastVisitMs)) {
        return false;
    }

    const createdMs = Date.parse(transaction.created_at);

    return !Number.isNaN(createdMs) && createdMs > lastVisitMs;
}
