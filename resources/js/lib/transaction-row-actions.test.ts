import { describe, expect, it } from 'vitest';

import { getTransactionRowActions } from '@/lib/transaction-row-actions';
import type { ServerTransaction } from '@/types/transaction';

function transaction(
    overrides: Partial<ServerTransaction> = {},
): ServerTransaction {
    return {
        id: 'txn-1',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'MERCADONA S.A.',
        transaction_date: '2026-08-22',
        amount: -5340,
        currency_code: 'EUR',
        notes: null,
        source: 'enablebanking',
        created_at: '2026-08-22T00:00:00Z',
        updated_at: '2026-08-22T00:00:00Z',
        label_ids: [],
        ...overrides,
    } as ServerTransaction;
}

const handlers = {
    onEdit: () => {},
    onDuplicate: () => {},
    onReEvaluateRules: () => {},
    onAutomate: () => {},
    onDelete: () => {},
    onSplit: () => {},
    onUnsplit: () => {},
};

describe('getTransactionRowActions', () => {
    it('offers duplicating, splitting and deleting on an ordinary transaction', () => {
        const ids = getTransactionRowActions({
            transaction: transaction(),
            ...handlers,
        }).map((action) => action.id);

        expect(ids).toEqual([
            'edit',
            'duplicate',
            'split',
            're-evaluate-rules',
            'automate',
            'delete',
        ]);
    });

    it('replaces deleting with merging back on a part of a split', () => {
        const ids = getTransactionRowActions({
            transaction: transaction({ split_parent_id: 'txn-0' }),
            ...handlers,
        }).map((action) => action.id);

        expect(ids).toEqual([
            'edit',
            're-evaluate-rules',
            'automate',
            'unsplit',
        ]);
        expect(ids).not.toContain('delete');
        expect(ids).not.toContain('split');
        expect(ids).not.toContain('duplicate');
    });

    it('hands the transaction to the duplicate handler', () => {
        const duplicated: ServerTransaction[] = [];
        const source = transaction();

        getTransactionRowActions({
            ...handlers,
            transaction: source,
            onDuplicate: (selected) => duplicated.push(selected),
        })
            .find((action) => action.id === 'duplicate')
            ?.onSelect();

        expect(duplicated).toEqual([source]);
    });

    it('does not offer splitting a transaction of zero', () => {
        const ids = getTransactionRowActions({
            transaction: transaction({ amount: 0 }),
            ...handlers,
        }).map((action) => action.id);

        expect(ids).not.toContain('split');
    });
});
