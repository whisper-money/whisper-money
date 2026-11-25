import { useBalanceOwningAccountIds } from '@/hooks/use-owns-balance-updates';
import { type TransactionRowEditing } from '@/hooks/use-transaction-row-editing';
import { consoleDebug } from '@/lib/debug';
import { mergeAuthoritativeTransactions } from '@/lib/transaction-bulk-update';
import { reEvaluateTransactionRules } from '@/lib/transaction-re-evaluation';
import { isSplitPart } from '@/lib/transaction-splits';
import {
    type BulkUpdateResult,
    transactionSyncService,
} from '@/services/transaction-sync';
import { type Account } from '@/types/account';
import {
    type DecryptedTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { type TransactionTableSource } from '@/types/transaction-table-source';
import { __ } from '@/utils/i18n';
import { type RowSelectionState } from '@tanstack/react-table';
import {
    type Dispatch,
    type SetStateAction,
    useCallback,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';

interface TransactionBulkActionsOptions {
    /** Every row the table holds, to look the selected ones up in. */
    rows: DecryptedTransaction[];
    setRows: Dispatch<SetStateAction<DecryptedTransaction[]>>;
    rowSelection: RowSelectionState;
    setRowSelection: Dispatch<SetStateAction<RowSelectionState>>;
    accounts: Account[];
    /** The delete dialog is shared with the row menu's Delete. */
    editing: Pick<
        TransactionRowEditing,
        'deleteTransaction' | 'setDeleteTransaction' | 'isReEvaluating'
    >;
    /** The filters on the table, which an edit of every matching row sends. */
    filters: TransactionFilters;
    /**
     * The filters the server applies to the listed rows. Given only when the
     * table pages from the server, where a bulk action can reach every row
     * matching them, not just the loaded ones.
     */
    matchingFilters?: Record<string, unknown>;
    /** How many rows a filter set lists, for a removal reaching them all. */
    countMatching: TransactionTableSource['countMatching'];
    /** Reloads the rows after the server changed some it does not hand back. */
    onServerChanged?: () => void;
    /** Toast how many rows a bulk edit changed, or that it failed. */
    announceOutcome?: boolean;
    onBalanceUpdated?: () => void;
    onTransactionsChanged?: () => void;
}

/** A split transaction is filed under its lines, so a bulk category skips it. */
function categoryEditOutcome(result: BulkUpdateResult): string {
    return result.skipped_split_count > 0
        ? __(
              'Updated :updated transactions; skipped :skipped split transactions',
              {
                  updated: result.updated_count,
                  skipped: result.skipped_split_count,
              },
          )
        : labelsEditOutcome(result);
}

function labelsEditOutcome(result: BulkUpdateResult): string {
    return __('Updated :count transactions', { count: result.updated_count });
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
    accounts,
    editing: {
        deleteTransaction,
        setDeleteTransaction,
        isReEvaluating: isReEvaluatingRow,
    },
    filters,
    matchingFilters,
    countMatching,
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
    const [isReEvaluatingBulk, setIsReEvaluatingBulk] = useState(false);
    const [isSelectingAll, setIsSelectingAll] = useState(false);
    const [isRemoveLabelsDialogOpen, setIsRemoveLabelsDialogOpen] =
        useState(false);
    const [matchingCount, setMatchingCount] = useState<number | null>(null);
    const [countFailed, setCountFailed] = useState(false);
    const matchingCountRequest = useRef<AbortController | null>(null);

    const selectedIds = useMemo(
        () => Object.keys(rowSelection),
        [rowSelection],
    );
    const selectedCount = selectedIds.length;
    const canSelectAllMatching = matchingFilters !== undefined;
    const reachesAllMatching = canSelectAllMatching && isSelectingAll;
    const hasFilters = Object.keys(matchingFilters ?? {}).length > 0;

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
     * Applies a bulk edit to every row matching the filters, or to the ticked
     * rows. Either way the server hands the changed rows back, and those are
     * what the table shows: a split transaction it skipped stays as it was.
     */
    async function applyBulkEdit(
        changes:
            | { category_id: string | null }
            | { label_ids: string[] }
            | { remove_all_labels: true },
        outcome: {
            describe: (result: BulkUpdateResult) => string;
            /** Toast it on every table, not only those announcing outcomes. */
            alwaysAnnounce?: boolean;
        },
        failure: { log: string; toast: string },
    ): Promise<boolean> {
        if (selectedIds.length === 0 && !reachesAllMatching) {
            return false;
        }

        setIsBulkUpdating(true);
        let progressToastId: string | number | undefined;
        try {
            if (reachesAllMatching) {
                progressToastId = toast.loading(__('Updating transactions...'));
                const result = await transactionSyncService.updateByFilters(
                    filters,
                    changes,
                );
                toast.success(outcome.describe(result));
                setRows((previous) =>
                    mergeAuthoritativeTransactions(
                        previous,
                        result.transactions,
                    ),
                );
                clearSelection();
                onServerChanged?.();

                return true;
            }

            const result = await transactionSyncService.updateMany(
                selectedIds,
                changes,
            );
            setRows((previous) =>
                mergeAuthoritativeTransactions(previous, result.transactions),
            );

            if (announceOutcome || outcome.alwaysAnnounce) {
                toast.success(outcome.describe(result));
            }

            setRowSelection({});

            return true;
        } catch (error) {
            console.error(failure.log, error);
            if (announceOutcome) {
                toast.error(failure.toast);
            }

            return false;
        } finally {
            // Never leave "Updating transactions..." up after a failure. No
            // id would dismiss every toast, the success one included.
            if (progressToastId !== undefined) {
                toast.dismiss(progressToastId);
            }
            setIsBulkUpdating(false);
        }
    }

    async function handleBulkCategoryChange(categoryId: string | null) {
        const updated = await applyBulkEdit(
            { category_id: categoryId },
            { describe: categoryEditOutcome, alwaysAnnounce: true },
            {
                log: 'Failed to update transactions:',
                toast: __('Failed to update transactions'),
            },
        );

        if (updated && !reachesAllMatching) {
            onTransactionsChanged?.();
        }
    }

    async function applyLabelEdit(
        changes: { label_ids: string[] } | { remove_all_labels: true },
    ) {
        const updated = await applyBulkEdit(
            changes,
            { describe: labelsEditOutcome },
            {
                log: 'Failed to update transactions with labels:',
                toast: __('Failed to update transactions with labels'),
            },
        );

        if (updated && !reachesAllMatching) {
            onTransactionsChanged?.();
        }
    }

    /** Adds to the labels each row has, whichever those are. */
    async function handleBulkLabelsChange(labelIds: string[]) {
        if (labelIds.length > 0) {
            await applyLabelEdit({ label_ids: labelIds });
        }
    }

    /** A select-all has no number of its own: the dialog needs the real one. */
    async function countRowsLosingLabels() {
        const request = new AbortController();
        matchingCountRequest.current = request;
        setMatchingCount(null);
        setCountFailed(false);

        try {
            const count = await countMatching(filters, request.signal);

            if (!request.signal.aborted) {
                setMatchingCount(count);
            }
        } catch {
            if (!request.signal.aborted) {
                setCountFailed(true);
            }
        }
    }

    function handleRemoveAllLabelsClick() {
        if (selectedIds.length === 0 && !reachesAllMatching) {
            return;
        }

        setIsRemoveLabelsDialogOpen(true);

        if (reachesAllMatching) {
            void countRowsLosingLabels();
        }
    }

    function closeRemoveLabelsDialog() {
        matchingCountRequest.current?.abort();
        setIsRemoveLabelsDialogOpen(false);
    }

    async function handleRemoveAllLabels() {
        closeRemoveLabelsDialog();
        await applyLabelEdit({ remove_all_labels: true });
    }

    async function handleBulkReEvaluateRules() {
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');

        if (selectedIds.length === 0 && !reachesAllMatching) {
            return;
        }

        setIsReEvaluatingBulk(true);

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
            setIsReEvaluatingBulk(false);
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
            onRemoveAllLabels: handleRemoveAllLabelsClick,
            onDelete: handleBulkDeleteClick,
            onReEvaluateRules: handleBulkReEvaluateRules,
            isUpdating:
                isBulkUpdating || isReEvaluatingBulk || isReEvaluatingRow,
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
        removeLabelsDialog: {
            isOpen: isRemoveLabelsDialogOpen,
            isAllMatching: reachesAllMatching,
            hasFilters,
            selectedCount,
            matchingCount,
            countFailed,
            // Never blind: the number is known, or no filter is set and the
            // dialog says it is every transaction.
            canConfirm:
                !reachesAllMatching ||
                matchingCount !== null ||
                (countFailed && !hasFilters),
            onConfirm: handleRemoveAllLabels,
            onClose: closeRemoveLabelsDialog,
        },
    };
}

export type TransactionDeleteDialogState = ReturnType<
    typeof useTransactionBulkActions
>['deleteDialog'];

export type TransactionRemoveLabelsDialogState = ReturnType<
    typeof useTransactionBulkActions
>['removeLabelsDialog'];
