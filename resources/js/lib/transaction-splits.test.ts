import { describe, expect, it } from 'vitest';

import {
    canSplit,
    hasLegacySplitLines,
    isSplitBalanced,
    isSplitPart,
    movementIdOf,
    remainingCents,
    toSplitPayload,
    type SplitDraft,
} from '@/lib/transaction-splits';
import type { Transaction, TransactionSplit } from '@/types/transaction';

function draft(amount: number, categoryId: string | null = null): SplitDraft {
    return {
        key: `k-${amount}-${categoryId}`,
        categoryId,
        amount,
        labelIds: [],
    };
}

function transaction(overrides: Partial<Transaction> = {}): Transaction {
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
        ...overrides,
    };
}

const legacyLines = [
    {
        id: 'line-1',
        transaction_id: 'txn-1',
        category_id: 'cat-1',
        category: null,
        amount: -3000,
        position: 0,
    },
    {
        id: 'line-2',
        transaction_id: 'txn-1',
        category_id: 'cat-2',
        category: null,
        amount: -2340,
        position: 1,
    },
] satisfies TransactionSplit[];

describe('canSplit', () => {
    it('allows splitting a transaction that moved money', () => {
        expect(canSplit(transaction())).toBe(true);
    });

    it('refuses a transaction of zero', () => {
        expect(canSplit(transaction({ amount: 0 }))).toBe(false);
    });

    it('refuses a part of an existing split', () => {
        expect(canSplit(transaction({ split_parent_id: 'txn-0' }))).toBe(false);
        expect(isSplitPart(transaction({ split_parent_id: 'txn-0' }))).toBe(
            true,
        );
        expect(isSplitPart(transaction())).toBe(false);
    });

    it('refuses a transaction still split into lines until it is converted', () => {
        const legacy = transaction({ splits: legacyLines });

        expect(hasLegacySplitLines(legacy)).toBe(true);
        expect(canSplit(legacy)).toBe(false);
        expect(canSplit(transaction({ splits: [] }))).toBe(true);
    });
});

describe('movementIdOf', () => {
    it('reads a part as the original it was split from', () => {
        expect(movementIdOf(transaction({ split_parent_id: 'txn-0' }))).toBe(
            'txn-0',
        );
        expect(movementIdOf(transaction())).toBe('txn-1');
    });
});

describe('remainingCents', () => {
    it('counts what is still unassigned, whichever way the money moved', () => {
        expect(remainingCents(-5340, [draft(3490), draft(1000)])).toBe(850);
        expect(remainingCents(5340, [draft(3490), draft(1000)])).toBe(850);
    });

    it('goes negative once the parts overshoot', () => {
        expect(remainingCents(-5340, [draft(5000), draft(1000)])).toBe(-660);
    });
});

describe('isSplitBalanced', () => {
    it('needs the parts to add up exactly', () => {
        expect(isSplitBalanced(-5340, [draft(3490), draft(1850)])).toBe(true);
        expect(isSplitBalanced(-5340, [draft(3490), draft(1000)])).toBe(false);
    });

    it('refuses a part with no amount, even when the rest add up', () => {
        expect(isSplitBalanced(-5340, [draft(5340), draft(0)])).toBe(false);
    });

    it('refuses fewer than two parts', () => {
        expect(isSplitBalanced(-5340, [draft(5340)])).toBe(false);
    });
});

describe('toSplitPayload', () => {
    it('hands the original sign back to every part, so one cannot point the wrong way', () => {
        expect(
            toSplitPayload(-5340, [draft(3490, 'cat-1'), draft(1850)]),
        ).toEqual([
            { amount: -3490, category_id: 'cat-1', label_ids: [] },
            { amount: -1850, category_id: null, label_ids: [] },
        ]);
    });

    it('keeps income positive', () => {
        expect(toSplitPayload(5340, [draft(3490), draft(1850)])).toEqual([
            { amount: 3490, category_id: null, label_ids: [] },
            { amount: 1850, category_id: null, label_ids: [] },
        ]);
    });
});
