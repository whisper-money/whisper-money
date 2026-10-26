import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    mergeUpdatedTransaction,
    useTransactionRowEditing,
} from './use-transaction-row-editing';

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

function transaction(
    overrides: Partial<ServerTransaction> = {},
): ServerTransaction {
    return {
        id: 'tx-1',
        description: 'Coffee',
        amount: '-350',
        currency_code: 'EUR',
        transaction_date: '2026-10-01',
        account_id: 'account-1',
        category_id: null,
        ...overrides,
    } as ServerTransaction;
}

describe('mergeUpdatedTransaction', () => {
    it('keeps the relations a partial payload leaves out', () => {
        const account = { id: 'account-1', name: 'Main' };
        const category = { id: 'category-1', name: 'Food' };
        const rows = [
            transaction({
                account,
                category,
            } as unknown as Partial<ServerTransaction>),
            transaction({ id: 'tx-2' }),
        ];

        const merged = mergeUpdatedTransaction(rows, {
            ...transaction({ description: 'Tea' }),
            account: undefined,
            category: undefined,
        });

        expect(merged[0].description).toBe('Tea');
        expect(merged[0].account).toBe(account);
        expect(merged[0].category).toBe(category);
        expect(merged[1]).toBe(rows[1]);
    });

    it('takes a category the payload clears', () => {
        const rows = [
            transaction({
                category: { id: 'category-1' },
            } as unknown as Partial<ServerTransaction>),
        ];

        const merged = mergeUpdatedTransaction(rows, {
            ...transaction(),
            category: null,
        } as unknown as ServerTransaction);

        expect(merged[0].category).toBeNull();
    });
});

const NO_LABELS: Label[] = [];

function renderEditing(onRowUpdated?: () => void) {
    return renderHook(() => {
        const [rows, setRows] = useState([transaction()]);
        const editing = useTransactionRowEditing({
            initialLabels: NO_LABELS,
            setRows,
            onRowUpdated,
        });

        return { rows, editing };
    });
}

describe('useTransactionRowEditing', () => {
    it('opens the dialog each row action names', () => {
        const { result } = renderEditing();
        const row = result.current.rows[0];

        act(() => result.current.editing.rowActions.onEdit(row));
        act(() => result.current.editing.rowActions.onDuplicate(row));
        act(() => result.current.editing.rowActions.onSplit(row));
        act(() => result.current.editing.rowActions.onUnsplit(row));
        act(() => result.current.editing.rowActions.onDelete(row));
        act(() => result.current.editing.rowActions.onAutomate(row));

        expect(result.current.editing.editTransaction).toBe(row);
        expect(result.current.editing.duplicateTransaction).toBe(row);
        expect(result.current.editing.splitTransaction).toBe(row);
        expect(result.current.editing.unsplitTransaction).toBe(row);
        expect(result.current.editing.deleteTransaction).toBe(row);
        expect(result.current.editing.automateDialogOpen).toBe(true);
        expect(result.current.editing.automateCandidate).toEqual({
            transaction: row,
            category: null,
        });
    });

    it('edits the row in place and tells the page', () => {
        const onRowUpdated = vi.fn();
        const { result } = renderEditing(onRowUpdated);

        act(() =>
            result.current.editing.updateTransaction(
                transaction({ description: 'Tea' }),
            ),
        );

        expect(result.current.rows[0].description).toBe('Tea');
        expect(onRowUpdated).toHaveBeenCalledOnce();
    });

    it('keeps a created label, sorted by name', () => {
        const { result } = renderEditing();

        act(() =>
            result.current.editing.handleLabelCreated({
                id: 'b',
                name: 'Trips',
            } as never),
        );
        act(() =>
            result.current.editing.handleLabelCreated({
                id: 'a',
                name: 'Home',
            } as never),
        );

        expect(
            result.current.editing.labels.map((label) => label.name),
        ).toEqual(['Home', 'Trips']);
    });
});
