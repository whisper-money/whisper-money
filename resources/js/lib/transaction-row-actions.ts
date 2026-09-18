import { canSplit, isSplitPart } from '@/lib/transaction-splits';
import { type ServerTransaction } from '@/types/transaction';
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
    transaction: ServerTransaction;
    onEdit: (transaction: ServerTransaction) => void;
    onReEvaluateRules: (transaction: ServerTransaction) => void;
    onAutomate: (transaction: ServerTransaction) => void;
    onDelete: (transaction: ServerTransaction) => void;
    onSplit: (transaction: ServerTransaction) => void;
    onUnsplit: (transaction: ServerTransaction) => void;
}

/**
 * What a row offers, shared by the dropdown in the actions column and the
 * right-click menu on the row itself, so the two can never drift apart.
 */
export function getTransactionRowActions({
    transaction,
    onEdit,
    onReEvaluateRules,
    onAutomate,
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

    if (canSplit(transaction)) {
        actions.push({
            id: 'split',
            label: __('Split'),
            onSelect: () => onSplit(transaction),
        });
    }

    actions.push(
        {
            id: 're-evaluate-rules',
            label: __('Re-evaluate rules'),
            onSelect: () => onReEvaluateRules(transaction),
        },
        {
            id: 'automate',
            label: __('Automatize categorization'),
            onSelect: () => onAutomate(transaction),
        },
    );

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
