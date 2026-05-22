import { mergeUniqueTransactions } from '@/components/automation-rules/apply-automation-rule-flow';
import type { ServerTransaction } from '@/types/transaction';
import { describe, expect, it } from 'vitest';

const transaction = (id: string, description: string): ServerTransaction => ({
    id,
    user_id: 'user-id',
    account_id: 'account-id',
    category_id: null,
    description,
    description_iv: null,
    transaction_date: '2026-05-22',
    amount: -1000,
    currency_code: 'USD',
    notes: null,
    notes_iv: null,
    source: 'imported',
    created_at: '2026-05-22T00:00:00.000Z',
    updated_at: '2026-05-22T00:00:00.000Z',
});

describe('mergeUniqueTransactions', () => {
    it('removes duplicates when replacing the preview list', () => {
        const grocery = transaction('tx-1', 'Grocery');

        expect(
            mergeUniqueTransactions([], [grocery, grocery], true).map(
                (item) => item.id,
            ),
        ).toEqual(['tx-1']);
    });

    it('keeps existing rows and ignores duplicate paginated rows', () => {
        const grocery = transaction('tx-1', 'Grocery');
        const coffee = transaction('tx-2', 'Coffee');

        expect(
            mergeUniqueTransactions([grocery], [grocery, coffee], false).map(
                (item) => item.id,
            ),
        ).toEqual(['tx-1', 'tx-2']);
    });
});
