import { describe, expect, it } from 'vitest';

import { type DecryptedTransaction } from '@/types/transaction';
import { mergeReEvaluatedTransaction } from './transaction-re-evaluation';

function transaction(
    overrides: Partial<DecryptedTransaction> = {},
): DecryptedTransaction {
    return {
        id: 'transaction-1',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'Coffee',
        description_iv: null,
        transaction_date: '2026-05-11',
        amount: -450,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        source: 'imported',
        label_ids: [],
        created_at: '2026-05-11T00:00:00.000000Z',
        updated_at: '2026-05-11T00:00:00.000000Z',
        decryptedDescription: 'Coffee',
        decryptedNotes: null,
        ...overrides,
    };
}

describe('mergeReEvaluatedTransaction', () => {
    it('derives label ids from returned labels', () => {
        const merged = mergeReEvaluatedTransaction(
            transaction(),
            transaction({
                labels: [
                    {
                        id: 'label-1',
                        user_id: 'user-1',
                        name: 'Work',
                        color: 'blue',
                        created_at: '2026-05-11T00:00:00.000000Z',
                        updated_at: '2026-05-11T00:00:00.000000Z',
                        deleted_at: null,
                    },
                ],
            }),
        );

        expect(merged.label_ids).toEqual(['label-1']);
    });

    it('updates plaintext notes for immediate display', () => {
        const merged = mergeReEvaluatedTransaction(
            transaction(),
            transaction({
                notes: 'Needs review',
                notes_iv: null,
            }),
        );

        expect(merged.decryptedNotes).toBe('Needs review');
    });
});
