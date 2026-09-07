import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';

/**
 * Optimistic state for a bulk label change.
 *
 * Two things the callers kept getting wrong. The table renders labels from
 * `label_ids`, not `labels`, so both have to move together or the badges stay
 * on screen after the server already detached them. And `bulkUpdate` syncs the
 * labels, so the new set *replaces* the old one — merging here made the UI
 * disagree with the row that comes back on the next load.
 *
 * Rows outside `selectedIds` are returned by reference so memoised rows do not
 * re-render.
 */
export function applyBulkLabels(
    transactions: DecryptedTransaction[],
    selectedIds: string[],
    labelIds: string[],
    allLabels: Label[],
): DecryptedTransaction[] {
    const selected = new Set(selectedIds);
    const labels = allLabels.filter((label) => labelIds.includes(label.id));

    return transactions.map((transaction) =>
        selected.has(transaction.id.toString())
            ? { ...transaction, label_ids: labelIds, labels }
            : transaction,
    );
}
