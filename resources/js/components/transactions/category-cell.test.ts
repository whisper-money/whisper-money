import type { TransactionSplit } from '@/types/transaction';
import { describe, expect, it } from 'vitest';
import { buildSplitBreakdown } from './split-breakdown';

const splits = [
    {
        id: 'split-2',
        transaction_id: 'tx-1',
        category_id: 'travel',
        category: null,
        amount: -300,
        position: 1,
    },
    {
        id: 'split-1',
        transaction_id: 'tx-1',
        category_id: 'food',
        category: null,
        amount: -700,
        position: 0,
    },
] satisfies TransactionSplit[];

describe('buildSplitBreakdown', () => {
    it('returns every line in persisted position order without a category filter', () => {
        expect(buildSplitBreakdown(splits)).toEqual([
            { category: null, amount: -700, position: 0, isRest: false },
            { category: null, amount: -300, position: 1, isRest: false },
        ]);
    });

    it('shows matching lines and reconciles nonmatching lines as Rest', () => {
        expect(buildSplitBreakdown(splits, new Set(['food']))).toEqual([
            { category: null, amount: -700, position: 0, isRest: false },
            { category: null, amount: -300, position: 2, isRest: true },
        ]);
    });

    it('shows a single Rest line when the active filter matches no split', () => {
        expect(buildSplitBreakdown(splits, new Set())).toEqual([
            { category: null, amount: -1000, position: 2, isRest: true },
        ]);
    });
});
