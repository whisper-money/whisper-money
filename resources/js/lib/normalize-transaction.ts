import { type ServerTransaction } from '@/types/transaction';

/**
 * Everything the table reads that the payload does not carry ready to use: a
 * date trimmed to its day, the account's bank hoisted onto the row, and labels
 * present as both the objects and the ids the columns filter on.
 */
export function normalizeTransaction(tx: ServerTransaction): ServerTransaction {
    return {
        ...tx,
        transaction_date: String(tx.transaction_date).slice(0, 10),
        notes: tx.notes || null,
        bank: tx.account?.bank || undefined,
        labels: tx.labels || [],
        label_ids: tx.labels?.map((l) => l.id) || [],
    };
}
