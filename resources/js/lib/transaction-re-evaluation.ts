import { type DecryptedTransaction } from '@/types/transaction';

export function mergeReEvaluatedTransaction(
    transaction: DecryptedTransaction,
    updated: DecryptedTransaction,
): DecryptedTransaction {
    const labels = updated.labels ?? transaction.labels;
    const labelIds = updated.labels
        ? updated.labels.map((label) => label.id)
        : (updated.label_ids ?? transaction.label_ids);

    return {
        ...transaction,
        category_id: updated.category_id,
        category: updated.category,
        labels,
        label_ids: labelIds,
        notes: updated.notes,
        notes_iv: updated.notes_iv,
        decryptedNotes:
            updated.notes_iv === null
                ? (updated.notes ?? null)
                : transaction.decryptedNotes,
    };
}
