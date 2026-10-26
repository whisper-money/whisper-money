import { single as reEvaluateSingle } from '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController';
import { type AutomateCategorizationCandidate } from '@/components/automation-rules/automate-categorization-dialog';
import { showAutomatizeToast } from '@/lib/automatize-toast';
import { consoleDebug } from '@/lib/debug';
import { captureEvent } from '@/lib/posthog';
import { mergeReEvaluatedTransaction } from '@/lib/transaction-re-evaluation';
import { type TransactionRowActionHandlers } from '@/lib/transaction-row-actions';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import axios from 'axios';
import {
    type Dispatch,
    type SetStateAction,
    useCallback,
    useEffect,
    useMemo,
    useState,
} from 'react';

export type AutomatizeSource = 'transaction_table' | 'edit_transaction_modal';

/**
 * Lays an edited transaction over the row it replaces. The relations the
 * response leaves out stay as they were, so the row never loses its account,
 * bank or category to a partial payload.
 */
export function mergeUpdatedTransaction(
    rows: ServerTransaction[],
    updated: ServerTransaction,
): ServerTransaction[] {
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
}: {
    initialLabels: Label[];
    setRows: Dispatch<SetStateAction<ServerTransaction[]>>;
    /** Called after a row is edited in place. */
    onRowUpdated?: () => void;
}) {
    const [labels, setLabels] = useState<Label[]>(() => initialLabels);

    useEffect(() => {
        setLabels(initialLabels);
    }, [initialLabels]);

    const handleLabelCreated = useCallback((label: Label) => {
        setLabels((previous) => sortedWith(previous, label));
    }, []);

    const [editTransaction, setEditTransaction] =
        useState<ServerTransaction | null>(null);
    const [duplicateTransaction, setDuplicateTransaction] =
        useState<ServerTransaction | null>(null);
    const [deleteTransaction, setDeleteTransaction] =
        useState<ServerTransaction | null>(null);
    const [splitTransaction, setSplitTransaction] =
        useState<ServerTransaction | null>(null);
    const [unsplitTransaction, setUnsplitTransaction] =
        useState<ServerTransaction | null>(null);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const [automateDialogOpen, setAutomateDialogOpen] = useState(false);
    const [automateCandidate, setAutomateCandidate] =
        useState<AutomateCategorizationCandidate | null>(null);

    const updateTransaction = useCallback(
        (updatedTransaction: ServerTransaction) => {
            setRows((previous) =>
                mergeUpdatedTransaction(previous, updatedTransaction),
            );
            onRowUpdated?.();
        },
        [setRows, onRowUpdated],
    );

    const handleReEvaluateRules = useCallback(
        async (transaction: ServerTransaction) => {
            consoleDebug('=== Re-evaluating rules for single transaction ===');

            setIsReEvaluating(true);
            try {
                const response = await axios.post<{
                    data: ServerTransaction;
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
            transaction: ServerTransaction,
            category: Category,
            source: AutomatizeSource,
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

    const openAutomateDialog = useCallback((transaction: ServerTransaction) => {
        captureEvent('automation_rule_toast_automatize_clicked', {
            source: 'row_menu',
        });
        setAutomateCandidate({
            transaction,
            category: transaction.category ?? null,
        });
        setAutomateDialogOpen(true);
    }, []);

    const rowActions = useMemo<TransactionRowActionHandlers>(
        () => ({
            onEdit: setEditTransaction,
            onDuplicate: setDuplicateTransaction,
            onReEvaluateRules: handleReEvaluateRules,
            onDelete: setDeleteTransaction,
            onAutomate: openAutomateDialog,
            onSplit: setSplitTransaction,
            onUnsplit: setUnsplitTransaction,
        }),
        [handleReEvaluateRules, openAutomateDialog],
    );

    return {
        labels,
        handleLabelCreated,
        rowActions,
        updateTransaction,
        handleCategorized,
        editTransaction,
        setEditTransaction,
        duplicateTransaction,
        setDuplicateTransaction,
        deleteTransaction,
        setDeleteTransaction,
        splitTransaction,
        setSplitTransaction,
        unsplitTransaction,
        setUnsplitTransaction,
        isReEvaluating,
        setIsReEvaluating,
        automateDialogOpen,
        setAutomateDialogOpen,
        automateCandidate,
    };
}

export type TransactionRowEditing = ReturnType<typeof useTransactionRowEditing>;
