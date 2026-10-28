import { type TransactionFilters } from '@/types/transaction';
import { describe, expect, it } from 'vitest';
import { describeActiveFilters } from './transaction-filter-chips';

const none: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    categoryTypes: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
    aiCategorizedOnly: false,
};

const sources = {
    categories: [{ id: 'cat-1', name: 'Groceries' }],
    accounts: [{ id: 'acc-1', name: 'Checking' }],
    labels: [{ id: 'lab-1', name: 'Home' }],
    locale: 'en-US',
};

describe('describeActiveFilters', () => {
    it('has nothing to say when no filter is set', () => {
        expect(describeActiveFilters(none, sources)).toEqual([]);
    });

    it('spells every active filter, ids as the names they stand for', () => {
        expect(
            describeActiveFilters(
                {
                    ...none,
                    searchText: 'coffee',
                    dateFrom: new Date(2026, 2, 1),
                    dateTo: new Date(2026, 2, 31),
                    amountMin: 10,
                    categoryIds: ['cat-1', 'deleted'],
                    accountIds: ['acc-1'],
                    labelIds: ['lab-1'],
                    creditorName: 'Shop',
                    aiCategorizedOnly: true,
                },
                sources,
            ),
        ).toEqual([
            '“coffee”',
            'Mar 1, 2026 – Mar 31, 2026',
            'From 10',
            'Groceries',
            'Checking',
            'Home',
            'Creditor: Shop',
            'Only show AI guesses',
        ]);
    });

    it('reads a single amount bound as a ceiling', () => {
        expect(
            describeActiveFilters({ ...none, amountMax: 50 }, sources),
        ).toEqual(['Up to 50']);
    });
});
