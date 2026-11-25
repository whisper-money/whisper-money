import { AutomateCategorizationDialog } from '@/components/automation-rules/automate-categorization-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { SplitTransactionDialog } from '@/components/transactions/split-transaction-dialog';
import { UnsplitTransactionDialog } from '@/components/transactions/unsplit-transaction-dialog';
import { type TransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { useRef } from 'react';

interface TransactionRowDialogsProps {
    editing: TransactionRowEditing;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    automationRules: AutomationRule[];
    /** Brings the new rows in once the duplicate dialog closes on a save. */
    onDuplicated: () => void;
    /** Brings the parts in once a split is made or merged back. */
    onSplitChanged: () => void;
}

/**
 * The dialogs a row action opens: edit, duplicate, split, merge back and
 * automate. Delete is not here, as it shares its confirmation with the bulk
 * delete of each table.
 */
export function TransactionRowDialogs({
    editing,
    categories,
    accounts,
    banks,
    automationRules,
    onDuplicated,
    onSplitChanged,
}: TransactionRowDialogsProps) {
    // A ref, not state: the dialog reports the save and closes in the same
    // batch, so a state flag would still read false in the close handler.
    const savedDuplicate = useRef(false);

    return (
        <>
            <EditTransactionDialog
                transaction={editing.editTransaction}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={editing.labels}
                automationRules={automationRules}
                open={!!editing.editTransaction}
                onOpenChange={(open) =>
                    !open && editing.setEditTransaction(null)
                }
                onSuccess={editing.updateTransaction}
                onCategorized={editing.handleCategorized}
                onLabelCreated={editing.handleLabelCreated}
                onDelete={editing.setDeleteTransaction}
                onSplit={
                    editing.canStartSplits
                        ? editing.setSplitTransaction
                        : undefined
                }
                rowActions={editing.dialogRowActions}
                mode="edit"
            />

            <SplitTransactionDialog
                transaction={editing.splitTransaction}
                categories={categories}
                labels={editing.labels}
                open={!!editing.splitTransaction}
                onOpenChange={(open) =>
                    !open && editing.setSplitTransaction(null)
                }
                onSuccess={onSplitChanged}
                onLabelCreated={editing.handleLabelCreated}
            />

            <UnsplitTransactionDialog
                transaction={editing.unsplitTransaction}
                categories={categories}
                onOpenChange={(open) =>
                    !open && editing.setUnsplitTransaction(null)
                }
                onSuccess={onSplitChanged}
            />

            <EditTransactionDialog
                transaction={null}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={editing.labels}
                automationRules={automationRules}
                open={!!editing.duplicateTransaction}
                onOpenChange={(open) => {
                    if (open) {
                        return;
                    }
                    editing.setDuplicateTransaction(null);
                    // Reloading on the save itself could take "Save and add
                    // another" down with the page, so it waits for the close.
                    if (savedDuplicate.current) {
                        savedDuplicate.current = false;
                        onDuplicated();
                    }
                }}
                onSuccess={() => {
                    savedDuplicate.current = true;
                }}
                onLabelCreated={editing.handleLabelCreated}
                mode="create"
                duplicateFrom={editing.duplicateTransaction}
            />

            <AutomateCategorizationDialog
                open={editing.automateDialogOpen}
                candidate={editing.automateCandidate}
                categories={categories}
                onOpenChange={editing.setAutomateDialogOpen}
            />

            <PostSaveApplyRulePrompt />
        </>
    );
}
