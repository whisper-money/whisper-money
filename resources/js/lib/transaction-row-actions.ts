import { canSplit, isSplitPart } from '@/lib/transaction-splits';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';

export interface TransactionRowAction {
    id: string;
    label: string;
    onSelect: () => void;
    variant?: 'destructive';
    /** Draw a separator above this item. */
    separated?: boolean;
}

interface TransactionRowActionsOptions {
    transaction: DecryptedTransaction;
    /**
     * Whether splitting is offered at all. Merging back is listed regardless, so
     * a part is never a dead end.
     */
    splitsEnabled: boolean;
    onEdit: (transaction: DecryptedTransaction) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    onSplit: (transaction: DecryptedTransaction) => void;
    onUnsplit: (transaction: DecryptedTransaction) => void;
}

/**
 * What a row offers, shared by the dropdown in the actions column and the
 * right-click menu on the row itself, so the two can never drift apart.
 */
export function getTransactionRowActions({
    transaction,
    splitsEnabled,
    onEdit,
    onReEvaluateRules,
    onDelete,
    onSplit,
    onUnsplit,
}: TransactionRowActionsOptions): TransactionRowAction[] {
    const actions: TransactionRowAction[] = [
        {
            id: 'edit',
            label: __('Edit'),
            onSelect: () => onEdit(transaction),
        },
    ];

    if (splitsEnabled && canSplit(transaction)) {
        actions.push({
            id: 'split',
            label: __('Split'),
            onSelect: () => onSplit(transaction),
        });
    }

    actions.push({
        id: 're-evaluate-rules',
        label: __('Re-evaluate rules'),
        onSelect: () => onReEvaluateRules(transaction),
    });

    // One part of a split cannot be deleted on its own: the rest would stop
    // adding up to what the account actually moved. Merging back is the way out.
    if (isSplitPart(transaction)) {
        actions.push({
            id: 'unsplit',
            label: __('Merge the split back'),
            onSelect: () => onUnsplit(transaction),
            separated: true,
        });

        return actions;
    }

    actions.push({
        id: 'delete',
        label: __('Delete'),
        onSelect: () => onDelete(transaction),
        variant: 'destructive',
    });

    return actions;
}
