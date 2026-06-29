import { describe, expect, it } from 'vitest';

import { firstSeenTransactionIndex } from './new-transactions';

const tx = (created_at: string) => ({ created_at });

// Newest-first, like the transactions table default sort.
const transactions = [
    tx('2026-06-29T10:00:00Z'), // new
    tx('2026-06-29T09:00:00Z'), // new
    tx('2026-06-20T08:00:00Z'), // seen
    tx('2026-06-19T08:00:00Z'), // seen
];

describe('firstSeenTransactionIndex', () => {
    it('returns the boundary between new and seen rows', () => {
        expect(
            firstSeenTransactionIndex(transactions, '2026-06-25T00:00:00Z'),
        ).toBe(2);
    });

    it('returns -1 when nothing is new (first row already seen)', () => {
        expect(
            firstSeenTransactionIndex(transactions, '2026-06-30T00:00:00Z'),
        ).toBe(-1);
    });

    it('returns -1 when every loaded row is new', () => {
        expect(
            firstSeenTransactionIndex(transactions, '2026-06-01T00:00:00Z'),
        ).toBe(-1);
    });

    it('returns -1 on a first visit (no stored timestamp)', () => {
        expect(firstSeenTransactionIndex(transactions, null)).toBe(-1);
    });

    it('returns -1 on an unparseable timestamp', () => {
        expect(firstSeenTransactionIndex(transactions, 'not-a-date')).toBe(-1);
    });
});
