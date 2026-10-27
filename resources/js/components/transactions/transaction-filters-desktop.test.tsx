import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type TransactionFilters as FiltersType } from '@/types/transaction';
import { formatLocalDate, toLocalDate } from '@/utils/date';
import { fireEvent, render, screen, within } from '@testing-library/react';
import axios from 'axios';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionFilters } from './transaction-filters';

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));
vi.mock('axios');

const accounts = [
    { id: 'acc-1', name: 'Checking', bank: null },
] as unknown as Account[];

const categories = [
    { id: 'groceries', name: 'Groceries', parent_id: null, color: 'green' },
    {
        id: 'restaurants',
        name: 'Restaurants',
        parent_id: null,
        color: 'orange',
    },
] as unknown as Category[];

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
    cashflowBlock: null,
};

function renderFilters(
    filters: Partial<FiltersType> = {},
    props: { hideAccountFilter?: boolean; enableSavedFilters?: boolean } = {},
) {
    const onFiltersChange = vi.fn();
    render(
        <TransactionFilters
            filters={{ ...emptyFilters, ...filters }}
            onFiltersChange={onFiltersChange}
            categories={categories}
            labels={[]}
            accounts={accounts}
            trailing={<button>columns</button>}
            countMatching={vi.fn()}
            {...props}
        />,
    );
    return onFiltersChange;
}

function lastFilters(onFiltersChange: ReturnType<typeof vi.fn>): FiltersType {
    return onFiltersChange.mock.calls.at(-1)![0] as FiltersType;
}

describe('Desktop transaction filters', () => {
    beforeEach(() => {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
        Element.prototype.scrollIntoView = vi.fn();
        Element.prototype.hasPointerCapture = vi.fn(() => false);
        vi.clearAllMocks();
        vi.mocked(axios.get).mockResolvedValue({ data: { data: [] } });
    });

    it('reads "All dates" and steps to the current month from there', () => {
        vi.useFakeTimers({ now: new Date(2026, 9, 27), toFake: ['Date'] });
        const onFiltersChange = renderFilters();

        expect(screen.getByTestId('period-stepper-label').textContent).toBe(
            'All dates',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Previous period' }),
        );

        const next = lastFilters(onFiltersChange);
        expect(formatLocalDate(next.dateFrom!)).toBe('2026-10-01');
        expect(formatLocalDate(next.dateTo!)).toBe('2026-10-31');
        vi.useRealTimers();
    });

    it('steps a month to the next one', () => {
        const onFiltersChange = renderFilters({
            dateFrom: toLocalDate('2026-10-01'),
            dateTo: toLocalDate('2026-10-31'),
        });

        expect(screen.getByTestId('period-stepper-label').textContent).toBe(
            'October 2026',
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next period' }));

        const next = lastFilters(onFiltersChange);
        expect(formatLocalDate(next.dateFrom!)).toBe('2026-11-01');
        expect(formatLocalDate(next.dateTo!)).toBe('2026-11-30');
    });

    it('opens the quick periods and custom dates from the label', () => {
        const onFiltersChange = renderFilters({
            dateFrom: toLocalDate('2026-05-20'),
            dateTo: toLocalDate('2026-05-31'),
        });

        fireEvent.click(screen.getByTestId('period-stepper-label'));
        fireEvent.click(screen.getByRole('button', { name: 'Quarter' }));

        const next = lastFilters(onFiltersChange);
        expect(formatLocalDate(next.dateFrom!)).toBe('2026-04-01');
        expect(formatLocalDate(next.dateTo!)).toBe('2026-06-30');

        fireEvent.click(screen.getByRole('button', { name: 'All dates' }));
        expect(lastFilters(onFiltersChange)).toMatchObject({
            dateFrom: null,
            dateTo: null,
        });
    });

    it('focuses the search with "/" through the shortcut system', () => {
        renderFilters();
        const search = screen.getByRole('searchbox', {
            name: 'Search transactions',
        });

        fireEvent.keyDown(document.body, { key: '/' });

        expect(document.activeElement).toBe(search);
        expect(search.getAttribute('aria-keyshortcuts')).toBe('/');
    });

    it('lists the facets in "+ Filter" and edits the picked one in place', () => {
        const onFiltersChange = renderFilters({}, { hideAccountFilter: true });

        fireEvent.click(screen.getByTestId('add-filter-button'));
        const menu = screen.getByRole('menu');
        expect(
            within(menu)
                .getAllByRole('menuitem')
                .map((item) => item.textContent),
        ).toEqual([
            'Type',
            'Amount',
            'Category',
            'Label',
            'Counterparty',
            'AI guesses only',
        ]);

        fireEvent.click(within(menu).getByRole('menuitem', { name: 'Type' }));
        fireEvent.click(screen.getByRole('button', { name: 'Expenses' }));

        expect(lastFilters(onFiltersChange)).toMatchObject({
            amountMin: null,
            amountMax: 0,
        });
    });

    it('offers the cashflow block only over a whole date range', () => {
        renderFilters({
            dateFrom: toLocalDate('2026-10-01'),
            dateTo: toLocalDate('2026-10-31'),
        });

        fireEvent.click(screen.getByTestId('add-filter-button'));

        expect(
            screen.getByRole('menuitem', { name: 'Cashflow block' }),
        ).toBeTruthy();
    });

    it('shows a chip per active facet that reopens its editor', () => {
        const onFiltersChange = renderFilters({
            categoryIds: ['groceries', 'restaurants'],
            amountMax: 0,
        });

        expect(screen.getByTestId('filter-chip-type').textContent).toBe(
            'TypeExpenses',
        );
        const chip = screen.getByTestId('filter-chip-category');
        expect(chip.textContent).toBe('CategoryGroceries, Restaurants');

        fireEvent.click(within(chip).getByText('Groceries, Restaurants'));
        fireEvent.click(screen.getByRole('option', { name: /Groceries/ }));

        expect(lastFilters(onFiltersChange).categoryIds).toEqual([
            'restaurants',
        ]);
    });

    it('removes one facet with its × and every facet with "Clear all"', () => {
        const dateFrom = toLocalDate('2026-10-01');
        const dateTo = toLocalDate('2026-10-31');
        const onFiltersChange = renderFilters({
            dateFrom,
            dateTo,
            categoryIds: ['groceries'],
            labelIds: ['lbl-1'],
            searchText: 'coffee',
        });

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove the Category filter' }),
        );
        expect(lastFilters(onFiltersChange)).toMatchObject({
            categoryIds: [],
            labelIds: ['lbl-1'],
        });

        // The period and the search have their own controls, and stay.
        fireEvent.click(screen.getByRole('button', { name: 'Clear all' }));
        expect(lastFilters(onFiltersChange)).toEqual({
            ...emptyFilters,
            dateFrom,
            dateTo,
            searchText: 'coffee',
        });
    });

    it('keeps an emptied chip and its editor open to pick another', () => {
        function Harness() {
            const [filters, setFilters] = useState<FiltersType>({
                ...emptyFilters,
                categoryIds: ['groceries'],
            });

            return (
                <TransactionFilters
                    filters={filters}
                    onFiltersChange={setFilters}
                    categories={categories}
                    labels={[]}
                    accounts={accounts}
                    countMatching={vi.fn()}
                />
            );
        }
        render(<Harness />);

        fireEvent.click(screen.getByText('Groceries'));
        fireEvent.click(screen.getByRole('option', { name: /Groceries/ }));

        expect(screen.getByTestId('filter-chip-category').textContent).toBe(
            'Category—',
        );
        fireEvent.click(screen.getByRole('option', { name: /Restaurants/ }));

        expect(screen.getByTestId('filter-chip-category').textContent).toBe(
            'CategoryRestaurants',
        );
    });

    it('shows no chips row without a facet, the period and search aside', () => {
        renderFilters({
            dateFrom: toLocalDate('2026-10-01'),
            dateTo: toLocalDate('2026-10-31'),
            searchText: 'coffee',
        });

        expect(screen.queryByTestId('filter-chips')).toBeNull();
    });

    it('saves the current filters as a view from the chips row', async () => {
        renderFilters(
            { categoryIds: ['groceries'] },
            { enableSavedFilters: true },
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save as view' }));

        expect(await screen.findByText('Save filter')).toBeTruthy();
    });

    it('keeps "Save as view" off the page without saved filters', () => {
        renderFilters({ categoryIds: ['groceries'] });

        expect(
            screen.queryByRole('button', { name: 'Save as view' }),
        ).toBeNull();
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('closes the toolbar with what the table hands it', () => {
        renderFilters();

        expect(screen.getByRole('button', { name: 'columns' })).toBeTruthy();
    });
});
