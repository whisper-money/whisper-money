import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { type RowSelectionState } from '@tanstack/react-table';
import { act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTransactionBulkActions } from './use-transaction-bulk-actions';

const sync = vi.hoisted(() => ({
    delete: vi.fn(),
    deleteMany: vi.fn(),
    updateMany: vi.fn(),
}));
const patch = vi.hoisted(() => vi.fn());
const reEvaluate = vi.hoisted(() => vi.fn());
const toastMock = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    loading: vi.fn(),
    dismiss: vi.fn(),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: sync,
}));
vi.mock('axios', () => ({ default: { patch } }));
vi.mock('@/lib/transaction-re-evaluation', () => ({
    reEvaluateTransactionRules: reEvaluate,
}));
vi.mock('sonner', () => ({ toast: toastMock }));
vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    bulkUpdate: { url: () => '/transactions/bulk' },
}));

const MANUAL = { id: 'manual', type: 'checking', banking_connection_id: null };
const CONNECTED = {
    id: 'connected',
    type: 'checking',
    banking_connection_id: 'connection',
};
const ACCOUNTS = [MANUAL, CONNECTED] as unknown as Account[];
const FOOD = { id: 'food', name: 'Food' } as unknown as Category;
const CATEGORIES = [FOOD];
const LABELS: Label[] = [];

function transaction(
    id: string,
    overrides: Partial<ServerTransaction> = {},
): ServerTransaction {
    return {
        id,
        description: id,
        amount: '-100',
        currency_code: 'EUR',
        transaction_date: '2026-10-01',
        account_id: 'connected',
        category_id: null,
        split_parent_id: null,
        ...overrides,
    } as ServerTransaction;
}

interface Options {
    matchingFilters?: Record<string, unknown>;
    onServerChanged?: () => void;
    announceOutcome?: boolean;
    onBalanceUpdated?: () => void;
    onTransactionsChanged?: () => void;
}

function renderBulk(
    initialRows: ServerTransaction[],
    selected: string[],
    options: Options = {},
) {
    return renderHook(() => {
        const [rows, setRows] = useState(initialRows);
        const [rowSelection, setRowSelection] = useState<RowSelectionState>(
            Object.fromEntries(selected.map((id) => [id, true])),
        );
        const [deleteTransaction, setDeleteTransaction] =
            useState<ServerTransaction | null>(null);
        const bulk = useTransactionBulkActions({
            rows,
            setRows,
            rowSelection,
            setRowSelection,
            categories: CATEGORIES,
            labels: LABELS,
            accounts: ACCOUNTS,
            editing: {
                deleteTransaction,
                setDeleteTransaction,
                isReEvaluating: false,
            },
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
        sync.updateMany.mockResolvedValue(undefined);
        patch.mockResolvedValue({ data: { count: 42 } });
        reEvaluate.mockResolvedValue('done');
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

    it('recategorizes the ticked rows in place and tells the page', async () => {
        const onTransactionsChanged = vi.fn();
        const { result } = renderBulk(
            [transaction('a'), transaction('b')],
            ['a'],
            { onTransactionsChanged },
        );

        await act(() =>
            result.current.bulk.barProps.onCategoryChange(
                'food' as unknown as number,
            ),
        );

        expect(sync.updateMany).toHaveBeenCalledWith(['a'], {
            category_id: 'food',
        });
        expect(result.current.rows[0].category).toBe(FOOD);
        expect(result.current.rows[1].category_id).toBeNull();
        expect(result.current.rowSelection).toEqual({});
        expect(onTransactionsChanged).toHaveBeenCalledOnce();
        expect(toastMock.success).not.toHaveBeenCalled();
    });

    it('announces the outcome when asked to', async () => {
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

        expect(patch).toHaveBeenCalledWith('/transactions/bulk', {
            filters: { search: 'coffee' },
            label_ids: ['label'],
        });
        expect(sync.updateMany).not.toHaveBeenCalled();
        expect(onServerChanged).toHaveBeenCalledOnce();
        expect(result.current.bulk.barProps.isSelectingAll).toBe(false);
    });

    it('clears the progress toast when the server rejects a bulk edit', async () => {
        toastMock.loading.mockReturnValue('progress');
        patch.mockRejectedValue(new Error('500'));
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
