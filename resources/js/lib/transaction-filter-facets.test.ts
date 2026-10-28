import { type TransactionFilters } from '@/types/transaction';
import { toLocalDate } from '@/utils/date';
import { describe, expect, it } from 'vitest';
import {
    amountTypeOf,
    facetChips,
    withAmountType,
    withoutFacets,
} from './transaction-filter-facets';

const empty: TransactionFilters = {
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
    cashflowBlock: null,
};

const sources = {
    categories: [
        { id: 'groceries', name: 'Groceries' },
        { id: 'restaurants', name: 'Restaurants' },
        { id: 'fuel', name: 'Fuel' },
    ],
    accounts: [{ id: 'acc-1', name: 'Checking' }],
    labels: [{ id: 'lbl-1', name: 'Holiday' }],
    locale: 'en-US',
};

function chipsOf(
    filters: Partial<TransactionFilters>,
    hideAccountFilter = false,
) {
    return facetChips(
        { ...empty, ...filters },
        { ...sources, hideAccountFilter },
    ).map((chip) => `${chip.name}: ${chip.value}`);
}

describe('facetChips', () => {
    it('shows no chip for the period or the search', () => {
        expect(
            chipsOf({
                dateFrom: toLocalDate('2026-10-01'),
                dateTo: toLocalDate('2026-10-31'),
                searchText: 'coffee',
            }),
        ).toEqual([]);
    });

    it('splits a side-only amount range into a type chip alone', () => {
        expect(chipsOf({ amountMax: 0 })).toEqual(['Type: Expenses']);
        expect(chipsOf({ amountMin: 0 })).toEqual(['Type: Income']);
    });

    it('folds the side into one amount chip once sizes are typed', () => {
        expect(chipsOf({ amountMin: -100, amountMax: -30 })).toEqual([
            'Amount: Expenses 30 – 100',
        ]);
        expect(chipsOf({ amountMin: 50, amountMax: null })).toEqual([
            'Amount: Income ≥ 50',
        ]);
    });

    it('keeps a range spanning both sides as a signed amount chip', () => {
        expect(chipsOf({ amountMin: -50, amountMax: 30 })).toEqual([
            'Amount: -50 – 30',
        ]);
    });

    it('names the picked categories, summing up past two', () => {
        expect(
            chipsOf({ categoryIds: ['groceries', 'uncategorized'] }),
        ).toEqual(['Category: Groceries, Uncategorized']);
        expect(
            chipsOf({ categoryIds: ['groceries', 'restaurants', 'fuel'] }),
        ).toEqual(['Category: Groceries, Restaurants +1']);
    });

    it('names the picked category types before the categories', () => {
        expect(
            chipsOf({
                categoryIds: ['groceries'],
                categoryTypes: ['expense', 'savings', 'investment'],
            }),
        ).toEqual([
            'Category type: Expense, Savings +1',
            'Category: Groceries',
        ]);
    });

    it('leaves the accounts out on a page already narrowed to one', () => {
        expect(chipsOf({ accountIds: ['acc-1'] })).toEqual([
            'Account: Checking',
        ]);
        expect(chipsOf({ accountIds: ['acc-1'] }, true)).toEqual([]);
    });

    it('shows one chip per counterparty name, then AI and the block', () => {
        expect(
            chipsOf({
                labelIds: ['lbl-1'],
                creditorName: 'Amazon',
                debtorName: 'Bob',
                aiCategorizedOnly: true,
                cashflowBlock: 'variable',
            }),
        ).toEqual([
            'Label: Holiday',
            'Creditor: Amazon',
            'Debtor: Bob',
            'Categorized by: AI',
            'Block: Variable expense',
        ]);
    });

    it('takes off only its own filter when removed', () => {
        const filters = {
            ...empty,
            amountMin: -100,
            amountMax: -30,
            labelIds: ['lbl-1'],
        };
        const [amount, label] = facetChips(filters, sources);

        expect(amount.remove(filters)).toEqual({
            ...filters,
            amountMin: null,
            amountMax: null,
        });
        expect(label.remove(filters)).toEqual({ ...filters, labelIds: [] });

        const [type] = facetChips({ ...empty, amountMax: 0 }, sources);
        expect(type.remove({ ...empty, amountMax: 0 })).toEqual(empty);
    });
});

describe('withAmountType', () => {
    it('narrows to a side, keeping the sizes typed', () => {
        expect(withAmountType(empty, 'expense')).toMatchObject({
            amountMin: null,
            amountMax: 0,
        });
        expect(
            withAmountType(
                { ...empty, amountMin: -100, amountMax: -30 },
                'income',
            ),
        ).toMatchObject({ amountMin: 30, amountMax: 100 });
        expect(amountTypeOf(withAmountType(empty, 'income'))).toBe('income');
    });
});

describe('withoutFacets', () => {
    it('clears every chip, keeping the period and the search', () => {
        const dateFrom = toLocalDate('2026-10-01');
        const dateTo = toLocalDate('2026-10-31');

        expect(
            withoutFacets({
                ...empty,
                dateFrom,
                dateTo,
                searchText: 'coffee',
                categoryIds: ['groceries'],
                categoryTypes: ['savings'],
                amountMax: 0,
                cashflowBlock: 'variable',
            }),
        ).toEqual({ ...empty, dateFrom, dateTo, searchText: 'coffee' });
    });
});
