import { AutomateCategorizationDialog } from '@/components/automation-rules/automate-categorization-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { SplitTransactionDialog } from '@/components/transactions/split-transaction-dialog';
import { UnsplitTransactionDialog } from '@/components/transactions/unsplit-transaction-dialog';
import { type TransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { reloadPage } from '@/lib/leave-page';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';

interface TransactionRowDialogsProps {
    editing: TransactionRowEditing;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    automationRules: AutomationRule[];
    /** Brings the new row in once a duplicate is saved. */
    onDuplicated: () => void;
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
}: TransactionRowDialogsProps) {
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
                onSplit={editing.setSplitTransaction}
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
                onSuccess={reloadPage}
                onLabelCreated={editing.handleLabelCreated}
            />

            <UnsplitTransactionDialog
                transaction={editing.unsplitTransaction}
                categories={categories}
                onOpenChange={(open) =>
                    !open && editing.setUnsplitTransaction(null)
                }
                onSuccess={reloadPage}
            />

            <EditTransactionDialog
                transaction={null}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={editing.labels}
                automationRules={automationRules}
                open={!!editing.duplicateTransaction}
                onOpenChange={(open) =>
                    !open && editing.setDuplicateTransaction(null)
                }
                onSuccess={onDuplicated}
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
