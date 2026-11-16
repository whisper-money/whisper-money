import { single as reEvaluateSingle } from '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController';
import { type AutomateCategorizationCandidate } from '@/components/automation-rules/automate-categorization-dialog';
import {
    type AutomatizeSource,
    showAutomatizeToast,
} from '@/lib/automatize-toast';
import { consoleDebug } from '@/lib/debug';
import { captureEvent } from '@/lib/posthog';
import { mergeReEvaluatedTransaction } from '@/lib/transaction-re-evaluation';
import { type TransactionRowActionHandlers } from '@/lib/transaction-row-actions';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import axios from 'axios';
import {
    type Dispatch,
    type SetStateAction,
    useCallback,
    useEffect,
    useMemo,
    useState,
} from 'react';

/**
 * Lays an edited transaction over the row it replaces. The relations the
 * response leaves out stay as they were, so the row never loses its account,
 * bank or category to a partial payload.
 */
export function mergeUpdatedTransaction(
    rows: DecryptedTransaction[],
    updated: DecryptedTransaction,
): DecryptedTransaction[] {
    return rows.map((transaction) => {
        if (transaction.id !== updated.id) {
            return transaction;
        }

        return {
            ...transaction,
            ...updated,
            account:
                updated.account === undefined
                    ? transaction.account
                    : updated.account,
            bank: updated.bank === undefined ? transaction.bank : updated.bank,
            category:
                updated.category === undefined
                    ? (transaction.category ?? null)
                    : updated.category,
        };
    });
}

function sortedWith(labels: Label[], label: Label): Label[] {
    return [
        ...labels.filter((existing) => existing.id !== label.id),
        label,
    ].sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * What a transactions table needs to act on one row: the transaction each
 * dialog is open for, the handlers the row menus and columns call, and the
 * labels the dialogs can grow. Both tables use it, so a row action is wired
 * once; the table only says where its rows live.
 */
export function useTransactionRowEditing({
    initialLabels,
    setRows,
    onRowUpdated,
    resolveRow,
}: {
    initialLabels: Label[];
    setRows: Dispatch<SetStateAction<DecryptedTransaction[]>>;
    /** Called after a row is edited in place. */
    onRowUpdated?: () => void;
    /**
     * The transaction a dialog opens on, for rows that hold less than the
     * server does (a dashboard widget's). Rejects when it cannot be had.
     */
    resolveRow?: (
        transaction: DecryptedTransaction,
    ) => Promise<DecryptedTransaction>;
}) {
    const [labels, setLabels] = useState<Label[]>(() => initialLabels);

    useEffect(() => {
        setLabels(initialLabels);
    }, [initialLabels]);

    const handleLabelCreated = useCallback((label: Label) => {
        setLabels((previous) => sortedWith(previous, label));
    }, []);

    const [editTransaction, setEditTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [duplicateTransaction, setDuplicateTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [deleteTransaction, setDeleteTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const [automateDialogOpen, setAutomateDialogOpen] = useState(false);
    const [automateCandidate, setAutomateCandidate] =
        useState<AutomateCategorizationCandidate | null>(null);

    const updateTransaction = useCallback(
        (updatedTransaction: DecryptedTransaction) => {
            setRows((previous) =>
                mergeUpdatedTransaction(previous, updatedTransaction),
            );
            onRowUpdated?.();
        },
        [setRows, onRowUpdated],
    );

    const handleReEvaluateRules = useCallback(
        async (transaction: DecryptedTransaction) => {
            consoleDebug('=== Re-evaluating rules for single transaction ===');

            setIsReEvaluating(true);
            try {
                const response = await axios.post<{
                    data: DecryptedTransaction;
                }>(reEvaluateSingle({ transaction: transaction.id }).url);

                updateTransaction(
                    mergeReEvaluatedTransaction(
                        transaction,
                        response.data.data,
                    ),
                );
            } catch (error) {
                console.error('Failed to re-evaluate rules:', error);
            } finally {
                setIsReEvaluating(false);
            }
        },
        [updateTransaction],
    );

    const handleCategorized = useCallback(
        (
            transaction: DecryptedTransaction,
            category: Category,
            source: Extract<
                AutomatizeSource,
                'transaction_table' | 'edit_transaction_modal'
            >,
        ) => {
            const nextAutomateCandidate = { transaction, category };

            setAutomateCandidate(nextAutomateCandidate);
            showAutomatizeToast({
                source,
                onAutomatize: () => {
                    setAutomateCandidate(nextAutomateCandidate);
                    setAutomateDialogOpen(true);
                },
            });
        },
        [],
    );

    const openAutomateDialog = useCallback(
        (
            transaction: DecryptedTransaction,
            source: 'row_menu' | 'edit_dialog' = 'row_menu',
        ) => {
            captureEvent('automation_rule_toast_automatize_clicked', {
                source,
            });
            setAutomateCandidate({
                transaction,
                category: transaction.category ?? null,
            });
            setAutomateDialogOpen(true);
        },
        [],
    );

    const rowActions = useMemo<TransactionRowActionHandlers>(() => {
        const opening = (
            open: (transaction: DecryptedTransaction) => void,
        ): ((transaction: DecryptedTransaction) => void) =>
            resolveRow
                ? (transaction) => {
                      resolveRow(transaction).then(open, () => undefined);
                  }
                : open;

        return {
            onEdit: opening(setEditTransaction),
            onDuplicate: opening(setDuplicateTransaction),
            onReEvaluateRules: handleReEvaluateRules,
            onDelete: setDeleteTransaction,
            onAutomate: opening((transaction) =>
                openAutomateDialog(transaction),
            ),
        };
    }, [handleReEvaluateRules, openAutomateDialog, resolveRow]);

    /** The same actions, offered from the edit dialog's own menu. */
    const dialogRowActions = useMemo<TransactionRowActionHandlers>(
        () => ({
            ...rowActions,
            onAutomate: (transaction) =>
                openAutomateDialog(transaction, 'edit_dialog'),
        }),
        [rowActions, openAutomateDialog],
    );

    return {
        labels,
        handleLabelCreated,
        rowActions,
        dialogRowActions,
        updateTransaction,
        handleCategorized,
        editTransaction,
        setEditTransaction,
        duplicateTransaction,
        setDuplicateTransaction,
        deleteTransaction,
        setDeleteTransaction,
        isReEvaluating,
        automateDialogOpen,
        setAutomateDialogOpen,
        automateCandidate,
    };
}

export type TransactionRowEditing = ReturnType<typeof useTransactionRowEditing>;
