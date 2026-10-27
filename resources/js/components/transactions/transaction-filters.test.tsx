import { searchableCategories } from '@/lib/category-tree.fixture';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type TransactionFilters as FiltersType } from '@/types/transaction';
import { formatLocalDate, toLocalDate } from '@/utils/date';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionFilters } from './transaction-filters';

// The phone's bar and filters sheet; the desktop chips toolbar has its own tests.
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => true }));
vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

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
    cashflowBlock: null,
};

const october: Partial<FiltersType> = {
    dateFrom: toLocalDate('2026-10-01'),
    dateTo: toLocalDate('2026-10-31'),
};

function renderFilters(
    filters: Partial<FiltersType> = {},
    {
        categories = searchableCategories,
        hideAccountFilter = false,
        countMatching = vi.fn().mockResolvedValue(21),
    }: {
        categories?: Category[];
        hideAccountFilter?: boolean;
        countMatching?: ReturnType<typeof vi.fn>;
    } = {},
) {
    const onFiltersChange = vi.fn();
    render(
        <TransactionFilters
            filters={{ ...emptyFilters, ...filters }}
            onFiltersChange={onFiltersChange}
            categories={categories}
            labels={[]}
            accounts={accounts}
            hideAccountFilter={hideAccountFilter}
            countMatching={countMatching}
        />,
    );
    return { onFiltersChange, countMatching };
}

function openSheet() {
    fireEvent.click(screen.getByTestId('open-filters-sheet'));
    return screen.getByTestId('transaction-filters-sheet');
}

function applied(onFiltersChange: ReturnType<typeof vi.fn>): FiltersType {
    expect(onFiltersChange).toHaveBeenCalledTimes(1);
    return onFiltersChange.mock.calls[0][0] as FiltersType;
}

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

describe('TransactionFilters phone bar', () => {
    it('counts the active facets on "Filters", leaving the period out', () => {
        renderFilters({
            ...october,
            categoryIds: ['food'],
            // One amount facet, though a single typed bound sets both ends.
            amountMin: -30,
            amountMax: 0,
        });

        expect(screen.getByTestId('filters-badge')).toHaveTextContent('2');
    });

    it('shows no badge without a facet', () => {
        renderFilters(october);

        expect(screen.queryByTestId('filters-badge')).toBeNull();
    });

    it('steps the period month by month', () => {
        const { onFiltersChange } = renderFilters(october);

        expect(screen.getByTestId('period-stepper-label')).toHaveTextContent(
            'October 2026',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next period' }));

        const next = applied(onFiltersChange);
        expect(formatLocalDate(next.dateFrom!)).toBe('2026-11-01');
        expect(formatLocalDate(next.dateTo!)).toBe('2026-11-30');
    });
});

describe('TransactionFilters phone sheet', () => {
    it('keeps every change pending until "See N transactions" applies it', async () => {
        const { onFiltersChange, countMatching } = renderFilters();
        const sheet = openSheet();

        fireEvent.click(
            within(sheet).getByRole('button', { name: 'Expenses' }),
        );
        fireEvent.click(within(sheet).getByText('Categories'));
        fireEvent.click(within(sheet).getByRole('checkbox', { name: 'Food' }));

        expect(onFiltersChange).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.getByTestId('apply-filters')).toHaveTextContent(
                'See 21 transactions',
            ),
        );
        // Counted once the draft settled, not once per tap.
        expect(countMatching).toHaveBeenCalledTimes(1);
        expect(countMatching.mock.calls[0][0]).toMatchObject({
            amountMax: 0,
            categoryIds: ['food'],
        });

        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange)).toMatchObject({
            amountMin: null,
            amountMax: 0,
            categoryIds: ['food'],
        });
        expect(screen.queryByTestId('transaction-filters-sheet')).toBeNull();
    });

    it('reads "See transactions" while the count is on its way', () => {
        renderFilters(
            {},
            { countMatching: vi.fn(() => new Promise(() => {})) },
        );
        openSheet();

        expect(screen.getByTestId('apply-filters')).toHaveTextContent(
            /^See transactions$/,
        );
    });

    it('drops the draft on ✕', () => {
        const { onFiltersChange } = renderFilters();
        const sheet = openSheet();

        fireEvent.click(within(sheet).getByRole('button', { name: 'Income' }));
        fireEvent.click(within(sheet).getByRole('button', { name: 'Close' }));

        expect(onFiltersChange).not.toHaveBeenCalled();
        openSheet();
        expect(
            screen
                .getByRole('button', { name: 'All' })
                .getAttribute('aria-pressed'),
        ).toBe('true');
    });

    it('resets the facets and the period, keeping the search', () => {
        const { onFiltersChange } = renderFilters({
            ...october,
            searchText: 'coffee',
            categoryIds: ['transport'],
            aiCategorizedOnly: true,
        });
        const sheet = openSheet();

        fireEvent.click(within(sheet).getByRole('button', { name: 'Reset' }));
        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange)).toEqual({
            ...emptyFilters,
            searchText: 'coffee',
        });
    });

    it('turns the AI guesses switch on', () => {
        const { onFiltersChange } = renderFilters();
        const sheet = openSheet();

        fireEvent.click(
            within(sheet).getByRole('switch', { name: 'AI guesses only' }),
        );
        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange).aiCategorizedOnly).toBe(true);
    });

    it('picks a quick period from the period view', () => {
        const { onFiltersChange } = renderFilters({
            dateFrom: toLocalDate('2026-05-20'),
            dateTo: toLocalDate('2026-05-31'),
        });
        openSheet();

        fireEvent.click(screen.getByTestId('sheet-period-row'));
        fireEvent.click(screen.getByRole('button', { name: 'Quarter' }));
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.getByTestId('sheet-period-row')).toHaveTextContent(
            'Q2 2026',
        );
        fireEvent.click(screen.getByTestId('apply-filters'));

        const next = applied(onFiltersChange);
        expect(formatLocalDate(next.dateFrom!)).toBe('2026-04-01');
        expect(formatLocalDate(next.dateTo!)).toBe('2026-06-30');
    });

    it('lists five categories, the rest behind "View all"', () => {
        renderFilters();
        const sheet = openSheet();

        fireEvent.click(within(sheet).getByText('Categories'));
        // Uncategorized plus the four of the tree.
        expect(within(sheet).getAllByRole('checkbox')).toHaveLength(5);

        const many: Category[] = Array.from({ length: 8 }, (_, i) => ({
            ...searchableCategories[3],
            id: `c${i}`,
            name: `Category ${i}`,
        }));
        cleanup();
        renderFilters({}, { categories: many });
        const crowded = openSheet();
        fireEvent.click(within(crowded).getByText('Categories'));

        expect(within(crowded).getAllByRole('checkbox')).toHaveLength(5);
        fireEvent.click(
            within(crowded).getByRole('button', { name: 'View all (9)' }),
        );
        expect(within(crowded).getAllByRole('checkbox')).toHaveLength(9);
    });

    it('picks an account, and has no accounts row where the page is one', () => {
        const { onFiltersChange } = renderFilters();
        const sheet = openSheet();

        fireEvent.click(within(sheet).getByText('Accounts'));
        fireEvent.click(
            within(sheet).getByRole('checkbox', { name: /Checking/ }),
        );
        fireEvent.click(screen.getByTestId('apply-filters'));
        expect(applied(onFiltersChange).accountIds).toEqual(['acc-1']);

        cleanup();
        renderFilters({}, { hideAccountFilter: true });
        expect(within(openSheet()).queryByText('Accounts')).toBeNull();
    });

    it('offers the cashflow block only over a whole date range', () => {
        renderFilters();
        expect(
            within(openSheet()).queryByTestId('sheet-cashflow-block'),
        ).toBeNull();

        cleanup();
        const { onFiltersChange } = renderFilters(october);
        const sheet = openSheet();
        fireEvent.click(within(sheet).getByText('Cashflow block'));
        fireEvent.click(
            within(sheet).getByRole('checkbox', { name: 'Variable expense' }),
        );
        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange).cashflowBlock).toBe('variable');
    });

    it('applies the counterparty names', () => {
        const { onFiltersChange } = renderFilters();
        const sheet = openSheet();

        fireEvent.click(within(sheet).getByText('Counterparty'));
        fireEvent.change(within(sheet).getByLabelText('Creditor name'), {
            target: { value: 'Mercadona' },
        });
        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange).creditorName).toBe('Mercadona');
    });

    it('drops the cashflow block with the dates it narrows', () => {
        const { onFiltersChange } = renderFilters({
            ...october,
            cashflowBlock: 'variable',
        });
        openSheet();

        fireEvent.click(screen.getByTestId('sheet-period-row'));
        fireEvent.click(screen.getByRole('button', { name: 'All dates' }));
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange)).toMatchObject({
            dateFrom: null,
            dateTo: null,
            cashflowBlock: null,
        });
    });

    it('toggles the AI guesses from anywhere on its row', () => {
        const { onFiltersChange } = renderFilters();
        const sheet = openSheet();

        fireEvent.click(within(sheet).getByText('AI guesses only'));
        expect(
            within(sheet)
                .getByRole('switch', { name: 'AI guesses only' })
                .getAttribute('aria-checked'),
        ).toBe('true');
        fireEvent.click(screen.getByTestId('apply-filters'));

        expect(applied(onFiltersChange).aiCategorizedOnly).toBe(true);
    });
});
