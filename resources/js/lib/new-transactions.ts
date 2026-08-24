import { type Transaction } from '@/types/transaction';

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
