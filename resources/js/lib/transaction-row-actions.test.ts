import { describe, expect, it } from 'vitest';

import { getTransactionRowActions } from '@/lib/transaction-row-actions';
import type { DecryptedTransaction } from '@/types/transaction';

function transaction(
    overrides: Partial<DecryptedTransaction> = {},
): DecryptedTransaction {
    return {
        id: 'txn-1',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'MERCADONA S.A.',
        description_iv: null,
        transaction_date: '2026-08-22',
        amount: -5340,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        source: 'enablebanking',
        created_at: '2026-08-22T00:00:00Z',
        updated_at: '2026-08-22T00:00:00Z',
        decryptedDescription: 'MERCADONA S.A.',
        decryptedNotes: null,
        label_ids: [],
        ...overrides,
    } as DecryptedTransaction;
}

const handlers = {
    onEdit: () => {},
    onReEvaluateRules: () => {},
    onDelete: () => {},
    onSplit: () => {},
    onUnsplit: () => {},
};

describe('getTransactionRowActions', () => {
    it('offers splitting and deleting on an ordinary transaction', () => {
        const ids = getTransactionRowActions({
            transaction: transaction(),
            ...handlers,
        }).map((action) => action.id);

        expect(ids).toEqual(['edit', 'split', 're-evaluate-rules', 'delete']);
    });

    it('replaces deleting with merging back on a part of a split', () => {
        const ids = getTransactionRowActions({
            transaction: transaction({ split_parent_id: 'txn-0' }),
            ...handlers,
        }).map((action) => action.id);

        expect(ids).toEqual(['edit', 're-evaluate-rules', 'unsplit']);
        expect(ids).not.toContain('delete');
        expect(ids).not.toContain('split');
    });

    it('does not offer splitting a transaction of zero', () => {
        const ids = getTransactionRowActions({
            transaction: transaction({ amount: 0 }),
            ...handlers,
        }).map((action) => action.id);

        expect(ids).not.toContain('split');
    });
});
