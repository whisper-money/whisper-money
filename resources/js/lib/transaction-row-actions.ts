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

/** What each row action calls, handed down by the page that owns the dialogs. */
export interface TransactionRowActionHandlers {
    onEdit: (transaction: DecryptedTransaction) => void;
    onDuplicate: (transaction: DecryptedTransaction) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
    onAutomate: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    /**
     * Opens the split dialog. Left out while the TransactionSplitting feature
     * is off, which takes the action out of every menu: the server would
     * refuse the split anyway.
     */
    onSplit?: (transaction: DecryptedTransaction) => void;
    onUnsplit: (transaction: DecryptedTransaction) => void;
}

/**
 * A row still on the legacy client-side encryption holds its description and
 * notes as ciphertext, which the duplicate would copy as an empty description
 * or as unreadable text, so it is not offered there. Nor is a part of a split:
 * it only makes sense next to the other parts.
 */
function canBeDuplicated(transaction: DecryptedTransaction): boolean {
    return (
        !transaction.description_iv &&
        !transaction.notes_iv &&
        !isSplitPart(transaction)
    );
}

interface TransactionRowActionsOptions extends TransactionRowActionHandlers {
    transaction: DecryptedTransaction;
}

/**
 * What a row offers, shared by the dropdown in the actions column, the
 * right-click menus on the row itself and the edit dialog's "More actions",
 * so they can never drift apart.
 */
export function getTransactionRowActions({
    transaction,
    onEdit,
    onDuplicate,
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

    if (canBeDuplicated(transaction)) {
        actions.push({
            id: 'duplicate',
            label: __('Duplicate'),
            onSelect: () => onDuplicate(transaction),
        });
    }

    if (onSplit && canSplit(transaction)) {
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
