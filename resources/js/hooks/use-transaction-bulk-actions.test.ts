import { type Account } from '@/types/account';
import {
    type DecryptedTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { type RowSelectionState } from '@tanstack/react-table';
import { act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTransactionBulkActions } from './use-transaction-bulk-actions';

const sync = vi.hoisted(() => ({
    delete: vi.fn(),
    deleteMany: vi.fn(),
    updateMany: vi.fn(),
    updateByFilters: vi.fn(),
}));
const reEvaluate = vi.hoisted(() => vi.fn());
const countMatching = vi.hoisted(() => vi.fn());
const toastMock = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    loading: vi.fn(),
    dismiss: vi.fn(),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: sync,
}));
vi.mock('@/lib/transaction-re-evaluation', () => ({
    reEvaluateTransactionRules: reEvaluate,
}));
vi.mock('sonner', () => ({ toast: toastMock }));

const MANUAL = { id: 'manual', type: 'checking', banking_connection_id: null };
const CONNECTED = {
    id: 'connected',
    type: 'checking',
    banking_connection_id: 'connection',
};
const ACCOUNTS = [MANUAL, CONNECTED] as unknown as Account[];
const FILTERS = {
    searchText: 'coffee',
} as unknown as TransactionFilters;

function transaction(
    id: string,
    overrides: Partial<DecryptedTransaction> = {},
): DecryptedTransaction {
    return {
        id,
        description: id,
        amount: '-100',
        currency_code: 'EUR',
        transaction_date: '2026-10-01',
        account_id: 'connected',
        category_id: null,
        ...overrides,
    } as DecryptedTransaction;
}

/** What the server hands back after a bulk edit. */
function updateResult(
    transactions: DecryptedTransaction[] = [],
    skippedSplits = 0,
) {
    return {
        updated_count: transactions.length,
        skipped_split_count: skippedSplits,
        updated_ids: transactions.map((row) => row.id),
        skipped_split_ids: [],
        transactions,
    };
}

interface Options {
    matchingFilters?: Record<string, unknown>;
    onServerChanged?: () => void;
    announceOutcome?: boolean;
    onBalanceUpdated?: () => void;
    onTransactionsChanged?: () => void;
}

function renderBulk(
    initialRows: DecryptedTransaction[],
    selected: string[],
    options: Options = {},
) {
    return renderHook(() => {
        const [rows, setRows] = useState(initialRows);
        const [rowSelection, setRowSelection] = useState<RowSelectionState>(
            Object.fromEntries(selected.map((id) => [id, true])),
        );
        const [deleteTransaction, setDeleteTransaction] =
            useState<DecryptedTransaction | null>(null);
        const bulk = useTransactionBulkActions({
            rows,
            setRows,
            rowSelection,
            setRowSelection,
            accounts: ACCOUNTS,
            editing: {
                deleteTransaction,
                setDeleteTransaction,
                isReEvaluating: false,
            },
            filters: FILTERS,
            countMatching,
            ...options,
        });

        return {
            rows,
            rowSelection,
            setRowSelection,
            setDeleteTransaction,
            bulk,
        };
    });
}

describe('useTransactionBulkActions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sync.delete.mockResolvedValue(undefined);
        sync.deleteMany.mockResolvedValue(undefined);
        sync.updateMany.mockResolvedValue(updateResult());
        sync.updateByFilters.mockResolvedValue(updateResult());
        reEvaluate.mockResolvedValue('done');
        countMatching.mockResolvedValue(42);
    });

    it('offers "all matching filters" only when the table pages from the server', () => {
        const local = renderBulk([transaction('a')], ['a']);
        const server = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'x' },
        });

        expect(local.result.current.bulk.barProps.onSelectAll).toBeUndefined();
        expect(
            local.result.current.bulk.barProps.onSelectOnlyVisible,
        ).toBeUndefined();
        expect(server.result.current.bulk.barProps.onSelectAll).toBeInstanceOf(
            Function,
        );
    });

    it('stops reaching every matching row once a row is unticked', () => {
        const { result } = renderBulk(
            [transaction('a'), transaction('b')],
            ['a', 'b'],
            { matchingFilters: {} },
        );

        act(() => result.current.bulk.barProps.onSelectAll?.());
        expect(result.current.bulk.barProps.isSelectingAll).toBe(true);

        act(() => result.current.setRowSelection({ a: true }));
        expect(result.current.bulk.barProps.isSelectingAll).toBe(false);
    });

    it('lays the rows the server hands back over the ticked ones and tells the page', async () => {
        sync.updateMany.mockResolvedValue(
            updateResult([transaction('a', { category_id: 'food' })]),
        );
        const onTransactionsChanged = vi.fn();
        const { result } = renderBulk(
            [transaction('a'), transaction('b')],
            ['a'],
            { onTransactionsChanged },
        );

        await act(() => result.current.bulk.barProps.onCategoryChange('food'));

        expect(sync.updateMany).toHaveBeenCalledWith(['a'], {
            category_id: 'food',
        });
        expect(result.current.rows[0].category_id).toBe('food');
        expect(result.current.rows[1].category_id).toBeNull();
        expect(result.current.rowSelection).toEqual({});
        expect(onTransactionsChanged).toHaveBeenCalledOnce();
    });

    it('says which split transactions a bulk category skipped, on every table', async () => {
        sync.updateMany.mockResolvedValue(
            updateResult([transaction('a', { category_id: 'food' })], 1),
        );
        const { result } = renderBulk(
            [transaction('a'), transaction('split', { is_split: true })],
            ['a', 'split'],
        );

        await act(() => result.current.bulk.barProps.onCategoryChange('food'));

        expect(toastMock.success).toHaveBeenCalledWith(
            'Updated 1 transactions; skipped 1 split transactions',
        );
        expect(result.current.rows[1].category_id).toBeNull();
    });

    it('reports a label edit, as a category one, unless the rows reload anyway', async () => {
        const onTransactionsChanged = vi.fn();
        const edited = renderBulk([transaction('a')], ['a'], {
            onTransactionsChanged,
        });

        await act(() =>
            edited.result.current.bulk.barProps.onLabelsChange(['label']),
        );

        expect(onTransactionsChanged).toHaveBeenCalledOnce();

        onTransactionsChanged.mockClear();
        const allMatching = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'coffee' },
            onServerChanged: vi.fn(),
            onTransactionsChanged,
        });

        act(() => allMatching.result.current.bulk.barProps.onSelectAll?.());
        await act(() =>
            allMatching.result.current.bulk.barProps.onLabelsChange(['label']),
        );

        expect(onTransactionsChanged).not.toHaveBeenCalled();
    });

    it('announces a label edit only when asked to', async () => {
        const quiet = renderBulk([transaction('a')], ['a']);

        await act(() =>
            quiet.result.current.bulk.barProps.onLabelsChange(['label']),
        );

        expect(toastMock.success).not.toHaveBeenCalled();

        const { result } = renderBulk([transaction('a')], ['a'], {
            announceOutcome: true,
        });

        await act(() => result.current.bulk.barProps.onLabelsChange(['label']));

        expect(toastMock.success).toHaveBeenCalledOnce();
        expect(toastMock.dismiss).not.toHaveBeenCalled();
    });

    it('sends the filters instead of ids while reaching every matching row', async () => {
        const onServerChanged = vi.fn();
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'coffee' },
            onServerChanged,
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        await act(() => result.current.bulk.barProps.onLabelsChange(['label']));

        expect(sync.updateByFilters).toHaveBeenCalledWith(FILTERS, {
            label_ids: ['label'],
        });
        expect(sync.updateMany).not.toHaveBeenCalled();
        expect(onServerChanged).toHaveBeenCalledOnce();
        expect(result.current.bulk.barProps.isSelectingAll).toBe(false);
    });

    it('removes every label from the ticked rows only once confirmed', async () => {
        const onTransactionsChanged = vi.fn();
        const { result } = renderBulk([transaction('a')], ['a'], {
            onTransactionsChanged,
        });

        act(() => result.current.bulk.barProps.onRemoveAllLabels());

        expect(result.current.bulk.removeLabelsDialog.isOpen).toBe(true);
        expect(result.current.bulk.removeLabelsDialog.selectedCount).toBe(1);
        expect(sync.updateMany).not.toHaveBeenCalled();

        await act(() => result.current.bulk.removeLabelsDialog.onConfirm());

        expect(sync.updateMany).toHaveBeenCalledWith(['a'], {
            remove_all_labels: true,
        });
        expect(onTransactionsChanged).toHaveBeenCalledOnce();
    });

    it('sends nothing when the removal of every label is cancelled', () => {
        const { result } = renderBulk([transaction('a')], ['a']);

        act(() => result.current.bulk.barProps.onRemoveAllLabels());
        act(() => result.current.bulk.removeLabelsDialog.onClose());

        expect(result.current.bulk.removeLabelsDialog.isOpen).toBe(false);
        expect(sync.updateMany).not.toHaveBeenCalled();
        expect(sync.updateByFilters).not.toHaveBeenCalled();
    });

    it('removes every label from every matching row once confirmed', async () => {
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'coffee' },
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        act(() => result.current.bulk.barProps.onRemoveAllLabels());

        expect(result.current.bulk.removeLabelsDialog.isAllMatching).toBe(true);

        await act(() => result.current.bulk.removeLabelsDialog.onConfirm());

        expect(sync.updateByFilters).toHaveBeenCalledWith(FILTERS, {
            remove_all_labels: true,
        });
        expect(sync.updateMany).not.toHaveBeenCalled();
    });

    it('counts the rows a removal over every matching row reaches', async () => {
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'coffee' },
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        await act(async () => result.current.bulk.barProps.onRemoveAllLabels());

        expect(countMatching).toHaveBeenCalledWith(
            FILTERS,
            expect.any(AbortSignal),
        );
        expect(result.current.bulk.removeLabelsDialog).toMatchObject({
            hasFilters: true,
            matchingCount: 42,
            countFailed: false,
            canConfirm: true,
        });
    });

    it('holds the confirmation back until the rows are counted', async () => {
        let finishCount: (count: number) => void = () => {};
        countMatching.mockReturnValue(
            new Promise<number>((resolve) => {
                finishCount = resolve;
            }),
        );
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: {},
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        await act(async () => result.current.bulk.barProps.onRemoveAllLabels());

        expect(result.current.bulk.removeLabelsDialog).toMatchObject({
            hasFilters: false,
            matchingCount: null,
            canConfirm: false,
        });

        await act(async () => finishCount(7));

        expect(result.current.bulk.removeLabelsDialog).toMatchObject({
            matchingCount: 7,
            canConfirm: true,
        });
    });

    it('lets a failed count through only where the wording says all transactions', async () => {
        countMatching.mockRejectedValue(new Error('offline'));

        const unfiltered = renderBulk([transaction('a')], ['a'], {
            matchingFilters: {},
        });
        act(() => unfiltered.result.current.bulk.barProps.onSelectAll?.());
        await act(async () =>
            unfiltered.result.current.bulk.barProps.onRemoveAllLabels(),
        );

        const filtered = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'coffee' },
        });
        act(() => filtered.result.current.bulk.barProps.onSelectAll?.());
        await act(async () =>
            filtered.result.current.bulk.barProps.onRemoveAllLabels(),
        );

        expect(unfiltered.result.current.bulk.removeLabelsDialog).toMatchObject(
            { countFailed: true, canConfirm: true },
        );
        expect(filtered.result.current.bulk.removeLabelsDialog).toMatchObject({
            countFailed: true,
            canConfirm: false,
        });
    });

    it('drops the count when the dialog closes before it arrives', async () => {
        let finishCount: (count: number) => void = () => {};
        countMatching.mockReturnValue(
            new Promise<number>((resolve) => {
                finishCount = resolve;
            }),
        );
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: {},
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        await act(async () => result.current.bulk.barProps.onRemoveAllLabels());
        act(() => result.current.bulk.removeLabelsDialog.onClose());

        const signal = countMatching.mock.lastCall![1] as AbortSignal;

        await act(async () => finishCount(7));

        expect(signal.aborted).toBe(true);
        expect(result.current.bulk.removeLabelsDialog.matchingCount).toBeNull();
    });

    /** A select-all's dialog, closed once and left to count again. */
    async function reopenWithPendingCount(firstCount: () => Promise<number>) {
        countMatching.mockImplementationOnce(firstCount);
        const rendered = renderBulk([transaction('a')], ['a'], {
            matchingFilters: {},
        });

        act(() => rendered.result.current.bulk.barProps.onSelectAll?.());
        await act(async () =>
            rendered.result.current.bulk.barProps.onRemoveAllLabels(),
        );
        act(() => rendered.result.current.bulk.removeLabelsDialog.onClose());

        countMatching.mockReturnValue(new Promise<number>(() => {}));
        await act(async () =>
            rendered.result.current.bulk.barProps.onRemoveAllLabels(),
        );

        return rendered;
    }

    it('forgets the last count when the dialog opens again', async () => {
        const { result } = await reopenWithPendingCount(() =>
            Promise.resolve(7),
        );

        expect(result.current.bulk.removeLabelsDialog).toMatchObject({
            isOpen: true,
            matchingCount: null,
            countFailed: false,
            canConfirm: false,
        });
    });

    it('forgets the last failure when the dialog opens again', async () => {
        const { result } = await reopenWithPendingCount(() =>
            Promise.reject(new Error('offline')),
        );

        expect(result.current.bulk.removeLabelsDialog).toMatchObject({
            isOpen: true,
            matchingCount: null,
            countFailed: false,
            canConfirm: false,
        });
    });

    it('counts nothing for the ticked rows, whose number is known', () => {
        const { result } = renderBulk([transaction('a')], ['a']);

        act(() => result.current.bulk.barProps.onRemoveAllLabels());

        expect(countMatching).not.toHaveBeenCalled();
        expect(result.current.bulk.removeLabelsDialog.canConfirm).toBe(true);
    });

    it('does not ask to remove labels with nothing selected', () => {
        const { result } = renderBulk([transaction('a')], []);

        act(() => result.current.bulk.barProps.onRemoveAllLabels());

        expect(result.current.bulk.removeLabelsDialog.isOpen).toBe(false);
    });

    it('sends no empty label list', async () => {
        const { result } = renderBulk([transaction('a')], ['a']);

        await act(() => result.current.bulk.barProps.onLabelsChange([]));

        expect(sync.updateMany).not.toHaveBeenCalled();
    });

    it('clears the progress toast when the server rejects a bulk edit', async () => {
        toastMock.loading.mockReturnValue('progress');
        sync.updateByFilters.mockRejectedValue(new Error('500'));
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: {},
            announceOutcome: true,
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        await act(() => result.current.bulk.barProps.onCategoryChange(null));

        expect(toastMock.dismiss).toHaveBeenCalledWith('progress');
        expect(toastMock.error).toHaveBeenCalledWith(
            'Failed to update transactions',
        );
        expect(result.current.bulk.barProps.isSelectingAll).toBe(true);
    });

    it('re-evaluates by filters while reaching every matching row', async () => {
        const onServerChanged = vi.fn();
        const { result } = renderBulk([transaction('a')], ['a'], {
            matchingFilters: { search: 'coffee' },
            onServerChanged,
        });

        act(() => result.current.bulk.barProps.onSelectAll?.());
        await act(() => result.current.bulk.barProps.onReEvaluateRules());

        expect(reEvaluate).toHaveBeenCalledWith({
            filters: { search: 'coffee' },
        });
        expect(onServerChanged).toHaveBeenCalledOnce();
        expect(result.current.rowSelection).toEqual({});
    });

    it('keeps the selection when a bulk re-evaluation fails', async () => {
        reEvaluate.mockResolvedValue('failed');
        const { result } = renderBulk([transaction('a')], ['a']);

        await act(() => result.current.bulk.barProps.onReEvaluateRules());

        expect(reEvaluate).toHaveBeenCalledWith({ transaction_ids: ['a'] });
        expect(result.current.rowSelection).toEqual({ a: true });
    });

    it('refuses a bulk delete that includes part of a split', () => {
        const { result } = renderBulk(
            [transaction('a'), transaction('b', { split_parent_id: 'p' })],
            ['a', 'b'],
        );

        act(() => result.current.bulk.barProps.onDelete());

        expect(toastMock.error).toHaveBeenCalledOnce();
        expect(result.current.bulk.deleteDialog.transaction).toBeNull();
    });

    it('takes a split transaction into a bulk delete: it is one row with its lines', () => {
        const { result } = renderBulk(
            [transaction('a'), transaction('b', { is_split: true })],
            ['a', 'b'],
        );

        act(() => result.current.bulk.barProps.onDelete());

        expect(toastMock.error).not.toHaveBeenCalled();
        expect(result.current.bulk.deleteDialog.isBulk).toBe(true);
    });

    it('deletes the ticked rows and moves a manual balance', async () => {
        const onBalanceUpdated = vi.fn();
        const onTransactionsChanged = vi.fn();
        const { result } = renderBulk(
            [
                transaction('a', { account_id: 'manual' }),
                transaction('b'),
                transaction('c'),
            ],
            ['a', 'b'],
            { onBalanceUpdated, onTransactionsChanged },
        );

        act(() => result.current.bulk.barProps.onDelete());
        expect(result.current.bulk.deleteDialog.isBulk).toBe(true);
        expect(result.current.bulk.deleteDialog.touchesManualAccount).toBe(
            true,
        );

        await act(() => result.current.bulk.deleteDialog.onConfirm());

        expect(sync.deleteMany).toHaveBeenCalledWith(['a', 'b'], {
            updateBalance: true,
        });
        expect(result.current.rows.map((row) => row.id)).toEqual(['c']);
        expect(onBalanceUpdated).toHaveBeenCalledOnce();
        expect(onTransactionsChanged).toHaveBeenCalledOnce();
        expect(result.current.bulk.deleteDialog.transaction).toBeNull();
    });

    it('deletes one row from its menu without moving a connected balance', async () => {
        const onBalanceUpdated = vi.fn();
        const rows = [transaction('a'), transaction('b')];
        const { result } = renderBulk(rows, [], { onBalanceUpdated });

        act(() => result.current.setDeleteTransaction(rows[0]));
        expect(result.current.bulk.deleteDialog.isBulk).toBe(false);
        expect(result.current.bulk.deleteDialog.touchesManualAccount).toBe(
            false,
        );

        await act(() => result.current.bulk.deleteDialog.onConfirm());

        expect(sync.delete).toHaveBeenCalledWith('a', {
            updateBalance: false,
        });
        expect(result.current.rows.map((row) => row.id)).toEqual(['b']);
        expect(onBalanceUpdated).not.toHaveBeenCalled();
    });
});
