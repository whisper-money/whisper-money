import {
    expectFoodListedWithSubcategories,
    searchableCategories,
} from '@/lib/category-tree.fixture';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type TransactionFilters as FiltersType } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionFilters } from './transaction-filters';

const accounts: Account[] = [
    {
        id: 'acc-1',
        name: 'Checking',
        bank: {
            id: 'bank-1',
            user_id: null,
            name: 'Acme Bank',
            logo: 'https://example.com/acme.png',
        },
        type: 'checking',
        currency_code: 'USD',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
    },
    {
        id: 'acc-2',
        name: 'Savings',
        bank: null,
        type: 'savings',
        currency_code: 'USD',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
    },
];

const emptyFilters: FiltersType = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
    aiCategorizedOnly: false,
};

function renderFilters(
    filters: FiltersType,
    accountList: Account[] = accounts,
    categoryList: Category[] = [],
) {
    const onFiltersChange = vi.fn();
    render(
        <TransactionFilters
            filters={filters}
            onFiltersChange={onFiltersChange}
            categories={categoryList}
            labels={[]}
            accounts={accountList}
        />,
    );
    return onFiltersChange;
}

describe('TransactionFilters accounts dropdown', () => {
    beforeEach(() => {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
        Element.prototype.scrollIntoView = vi.fn();
        Element.prototype.hasPointerCapture = vi.fn(() => false);
        Element.prototype.setPointerCapture = vi.fn();
        Element.prototype.releasePointerCapture = vi.fn();
    });

    it('adds an account id when an account is selected', () => {
        const onFiltersChange = renderFilters(emptyFilters);

        fireEvent.click(screen.getByRole('button', { name: /Filters/ }));
        fireEvent.click(screen.getByText('Select accounts...'));
        fireEvent.click(screen.getByText('Checking'));

        expect(onFiltersChange).toHaveBeenCalledWith(
            expect.objectContaining({ accountIds: ['acc-1'] }),
        );
    });

    it('removes an account id when a selected account is toggled off', () => {
        const onFiltersChange = renderFilters({
            ...emptyFilters,
            accountIds: ['acc-1'],
        });

        fireEvent.click(screen.getByRole('button', { name: /Filters/ }));
        fireEvent.click(screen.getByText('1 selected'));
        fireEvent.click(screen.getByText('Checking'));

        expect(onFiltersChange).toHaveBeenCalledWith(
            expect.objectContaining({ accountIds: [] }),
        );
    });

    it('shows an empty state when there are no accounts', () => {
        renderFilters(emptyFilters, []);

        fireEvent.click(screen.getByRole('button', { name: /Filters/ }));
        fireEvent.click(screen.getByText('Select accounts...'));

        expect(screen.getByText('No accounts found.')).toBeInTheDocument();
    });
});

describe('TransactionFilters categories dropdown', () => {
    it('lists the subcategories of a parent that matches the search, highlighting the parent', async () => {
        renderFilters(emptyFilters, accounts, searchableCategories);

        fireEvent.click(screen.getByRole('button', { name: /Filters/ }));
        fireEvent.click(screen.getByText('Select categories...'));
        fireEvent.change(screen.getByPlaceholderText('Search categories...'), {
            target: { value: 'food' },
        });

        await expectFoodListedWithSubcategories();
    });
});
