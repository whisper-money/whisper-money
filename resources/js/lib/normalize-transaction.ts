import {
    type DecryptedTransaction,
    type ServerTransaction,
} from '@/types/transaction';

/**
 * Everything the table reads that the payload does not carry ready to use: a
 * date trimmed to its day, the text a legacy encrypted row can show, the
 * account's bank hoisted onto the row, and labels present as both the objects
 * and the ids the columns filter on.
 */
export function normalizeTransaction(
    tx: ServerTransaction,
): DecryptedTransaction {
    return {
        ...tx,
        transaction_date: String(tx.transaction_date).slice(0, 10),
        decryptedDescription: tx.description_iv ? '' : tx.description,
        decryptedNotes: tx.notes_iv ? null : tx.notes || null,
        bank: tx.account?.bank || undefined,
        labels: tx.labels || [],
        label_ids: tx.labels?.map((l) => l.id) || [],
    };
}
