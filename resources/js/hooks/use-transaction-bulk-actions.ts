import { bulkUpdate as bulkUpdateTransactions } from '@/actions/App/Http/Controllers/TransactionController';
import { useBalanceOwningAccountIds } from '@/hooks/use-owns-balance-updates';
import { type TransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { consoleDebug } from '@/lib/debug';
import { applyBulkLabels } from '@/lib/transaction-bulk-labels';
import { reEvaluateTransactionRules } from '@/lib/transaction-re-evaluation';
import { isSplitPart } from '@/lib/transaction-splits';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { type RowSelectionState } from '@tanstack/react-table';
import axios from 'axios';
import {
    type Dispatch,
    type SetStateAction,
    useCallback,
    useMemo,
    useState,
} from 'react';
import { toast } from 'sonner';

interface TransactionBulkActionsOptions {
    /** Every row the table holds, to look the selected ones up in. */
    rows: ServerTransaction[];
    setRows: Dispatch<SetStateAction<ServerTransaction[]>>;
    rowSelection: RowSelectionState;
    setRowSelection: Dispatch<SetStateAction<RowSelectionState>>;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    /** The delete dialog is shared with the row menu's Delete. */
    editing: Pick<
        TransactionRowEditing,
        'deleteTransaction' | 'setDeleteTransaction' | 'isReEvaluating'
    >;
    /**
     * The filters the server applies to the listed rows. Given only when the
     * table pages from the server, where a bulk action can reach every row
     * matching them, not just the loaded ones.
     */
    matchingFilters?: Record<string, unknown>;
    /** Reloads the rows after the server changed some it does not hand back. */
    onServerChanged?: () => void;
    /** Toast how many rows a bulk edit changed, or that it failed. */
    announceOutcome?: boolean;
    onBalanceUpdated?: () => void;
    onTransactionsChanged?: () => void;
}

/**
 * The bulk actions of a transactions table, and the delete confirmation they
 * share with the row menu: change category or labels, re-evaluate rules and
 * delete, on the ticked rows or, where the table pages from the server, on
 * every row matching its filters.
 */
export function useTransactionBulkActions({
    rows,
    setRows,
    rowSelection,
    setRowSelection,
    categories,
    labels,
    accounts,
    editing: {
        deleteTransaction,
        setDeleteTransaction,
        isReEvaluating: isReEvaluatingRow,
    },
    matchingFilters,
    onServerChanged,
    announceOutcome = false,
    onBalanceUpdated,
    onTransactionsChanged,
}: TransactionBulkActionsOptions) {
    const [isBulkDeleteMode, setIsBulkDeleteMode] = useState(false);
    const [updateBalanceOnDelete, setUpdateBalanceOnDelete] = useState(true);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [isBulkUpdating, setIsBulkUpdating] = useState(false);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const [isSelectingAll, setIsSelectingAll] = useState(false);

    const selectedIds = useMemo(
        () => Object.keys(rowSelection),
        [rowSelection],
    );
    const selectedCount = selectedIds.length;
    const canSelectAllMatching = matchingFilters !== undefined;
    const reachesAllMatching = canSelectAllMatching && isSelectingAll;

    const selectAllMatching = useCallback(() => setIsSelectingAll(true), []);
    const selectOnlyVisible = useCallback(() => setIsSelectingAll(false), []);

    // Unticking a row or a whole day means the user is narrowing down what
    // they see, so a bulk action must no longer reach every matching row.
    const [previousSelectedCount, setPreviousSelectedCount] =
        useState(selectedCount);

    if (selectedCount !== previousSelectedCount) {
        setPreviousSelectedCount(selectedCount);

        if (selectedCount < previousSelectedCount) {
            setIsSelectingAll(false);
        }
    }

    const manualAccountIds = useBalanceOwningAccountIds(accounts);

    const singleDeleteTouchesManualAccount =
        deleteTransaction !== null &&
        manualAccountIds.has(deleteTransaction.account_id);

    const bulkDeleteTouchesManualAccount = useMemo(
        () =>
            reachesAllMatching
                ? manualAccountIds.size > 0
                : rows.some(
                      (transaction) =>
                          selectedIds.includes(transaction.id) &&
                          manualAccountIds.has(transaction.account_id),
                  ),
        [reachesAllMatching, manualAccountIds, rows, selectedIds],
    );

    function clearSelection() {
        setRowSelection({});
        setIsSelectingAll(false);
    }

    function closeDeleteDialog() {
        setDeleteTransaction(null);
        setIsBulkDeleteMode(false);
        setUpdateBalanceOnDelete(true);
    }

    async function handleDelete() {
        if (!deleteTransaction) {
            return;
        }

        setIsDeleting(true);
        const balanceWasUpdated =
            updateBalanceOnDelete && singleDeleteTouchesManualAccount;
        try {
            await transactionSyncService.delete(deleteTransaction.id, {
                updateBalance: balanceWasUpdated,
            });
            if (balanceWasUpdated) {
                onBalanceUpdated?.();
            }
            onTransactionsChanged?.();
            setRows((previous) =>
                previous.filter(
                    (transaction) => transaction.id !== deleteTransaction.id,
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transaction:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    function handleBulkDeleteClick() {
        if (selectedIds.length === 0) {
            return;
        }

        // One part of a split cannot go on its own, and refusing the whole
        // batch up front beats failing halfway through it.
        const selectedSplitParts = rows.filter(
            (transaction) =>
                selectedIds.includes(transaction.id) &&
                isSplitPart(transaction),
        );

        if (selectedSplitParts.length > 0) {
            toast.error(
                __(
                    ':count of the selected transactions are splits. Merge them back before deleting them.',
                    { count: selectedSplitParts.length },
                ),
            );

            return;
        }

        const firstSelectedTransaction = rows.find(
            (transaction) => transaction.id === selectedIds[0],
        );

        if (firstSelectedTransaction) {
            setIsBulkDeleteMode(true);
            setDeleteTransaction(firstSelectedTransaction);
        }
    }

    async function handleBulkDelete() {
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkDeleting(true);
        const balanceMayHaveUpdated =
            updateBalanceOnDelete && bulkDeleteTouchesManualAccount;
        try {
            await transactionSyncService.deleteMany(selectedIds, {
                updateBalance: balanceMayHaveUpdated,
            });
            if (balanceMayHaveUpdated) {
                onBalanceUpdated?.();
            }
            onTransactionsChanged?.();
            setRows((previous) =>
                previous.filter(
                    (transaction) => !selectedIds.includes(transaction.id),
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transactions:', error);
        } finally {
            setIsBulkDeleting(false);
        }
    }

    /**
     * Applies a bulk edit to every row matching the filters on the server, or
     * to the ticked rows through the sync service and then in place.
     */
    async function applyBulkEdit(
        changes: { category_id: number | null } | { label_ids: string[] },
        applyLocally: (previous: ServerTransaction[]) => ServerTransaction[],
        failureMessage: string,
    ): Promise<boolean> {
        if (selectedIds.length === 0 && !reachesAllMatching) {
            return false;
        }

        setIsBulkUpdating(true);
        try {
            if (reachesAllMatching) {
                const toastId = toast.loading(__('Updating transactions...'));
                const response = await axios.patch<{ count: number }>(
                    bulkUpdateTransactions.url(),
                    { filters: matchingFilters, ...changes },
                );
                toast.dismiss(toastId);
                toast.success(
                    __(`Updated :count transactions`, {
                        count: response.data.count,
                    }),
                );
                clearSelection();
                onServerChanged?.();

                return true;
            }

            await transactionSyncService.updateMany(selectedIds, changes);
            setRows(applyLocally);

            if (announceOutcome) {
                toast.success(
                    __(`Updated :count transactions`, {
                        count: selectedIds.length,
                    }),
                );
            }

            setRowSelection({});

            return true;
        } catch (error) {
            console.error(failureMessage, error);
            if (announceOutcome) {
                toast.error(__(failureMessage));
            }

            return false;
        } finally {
            setIsBulkUpdating(false);
        }
    }

    async function handleBulkCategoryChange(categoryId: number | null) {
        const selectedCategory =
            categoryId === null
                ? null
                : (categories.find((category) => category.id === categoryId) ??
                  null);

        const updated = await applyBulkEdit(
            { category_id: categoryId },
            (previous) =>
                previous.map((transaction) =>
                    selectedIds.includes(transaction.id)
                        ? {
                              ...transaction,
                              category_id: categoryId,
                              category: selectedCategory,
                          }
                        : transaction,
                ),
            'Failed to update transactions',
        );

        if (updated && !reachesAllMatching) {
            onTransactionsChanged?.();
        }
    }

    async function handleBulkLabelsChange(labelIds: string[]) {
        await applyBulkEdit(
            { label_ids: labelIds },
            (previous) =>
                applyBulkLabels(previous, selectedIds, labelIds, labels),
            'Failed to update transactions with labels',
        );
    }

    async function handleBulkReEvaluateRules() {
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');

        if (selectedIds.length === 0 && !reachesAllMatching) {
            return;
        }

        setIsReEvaluating(true);

        try {
            const outcome = await reEvaluateTransactionRules(
                reachesAllMatching
                    ? { filters: matchingFilters }
                    : { transaction_ids: selectedIds },
            );

            // A queued job will still run, so the selection is handed off
            // either way; keeping it would invite a duplicate job.
            if (outcome !== 'failed') {
                clearSelection();
            }

            if (outcome === 'done') {
                onServerChanged?.();
            }
        } finally {
            setIsReEvaluating(false);
        }
    }

    return {
        selectOnlyVisible,
        /** What BulkActionsBar needs besides categories, labels and selection mode. */
        barProps: {
            selectedCount,
            isSelectingAll: reachesAllMatching,
            onSelectAll: canSelectAllMatching ? selectAllMatching : undefined,
            onSelectOnlyVisible: canSelectAllMatching
                ? selectOnlyVisible
                : undefined,
            onCategoryChange: handleBulkCategoryChange,
            onLabelsChange: handleBulkLabelsChange,
            onDelete: handleBulkDeleteClick,
            onReEvaluateRules: handleBulkReEvaluateRules,
            isUpdating: isBulkUpdating || isReEvaluating || isReEvaluatingRow,
        },
        deleteDialog: {
            transaction: deleteTransaction,
            isBulk: isBulkDeleteMode,
            selectedCount,
            touchesManualAccount: isBulkDeleteMode
                ? bulkDeleteTouchesManualAccount
                : singleDeleteTouchesManualAccount,
            updateBalance: updateBalanceOnDelete,
            setUpdateBalance: setUpdateBalanceOnDelete,
            isDeleting: isDeleting || isBulkDeleting,
            onConfirm: isBulkDeleteMode ? handleBulkDelete : handleDelete,
            onClose: closeDeleteDialog,
        },
    };
}

export type TransactionDeleteDialogState = ReturnType<
    typeof useTransactionBulkActions
>['deleteDialog'];
