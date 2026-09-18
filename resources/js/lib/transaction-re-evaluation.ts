import { type ServerTransaction } from '@/types/transaction';

export function mergeReEvaluatedTransaction(
    transaction: ServerTransaction,
    updated: ServerTransaction,
): ServerTransaction {
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
        notes: updated.notes ?? null,
    };
}
