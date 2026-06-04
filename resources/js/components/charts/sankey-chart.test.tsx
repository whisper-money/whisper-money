import { SankeyData } from '@/hooks/use-cashflow-data';
import { Category } from '@/types/category';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SankeyChart } from './sankey-chart';

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: () => ({ url: '/transactions' }),
}));

function category(id: string, name: string, color = '#ccc'): Category {
    return { id, name, color } as Category;
}

const data: SankeyData = {
    income_categories: [
        {
            category: category('salary', 'Salary'),
            category_id: 'salary',
            amount: 1000,
        },
    ],
    expense_categories: [
        {
            category: category('food', 'Food'),
            category_id: 'food',
            amount: 310,
            has_children: true,
        },
        {
            category: category('rent', 'Rent'),
            category_id: 'rent',
            amount: 500,
        },
    ],
    total_income: 1000,
    total_expense: 810,
};

const foodChildren: SankeyData = {
    income_categories: [],
    expense_categories: [
        {
            category: category('groceries', 'Groceries'),
            category_id: 'groceries',
            amount: 200,
        },
        {
            category: category('other-groceries', 'Other groceries'),
            category_id: 'other-groceries',
            amount: 110,
        },
    ],
    total_income: 0,
    total_expense: 310,
};

const period = {
    from: new Date('2026-06-01'),
    to: new Date('2026-06-30'),
};

describe('SankeyChart', () => {
    beforeEach(() => {
        global.fetch = vi.fn().mockResolvedValue({
            json: async () => foodChildren,
        }) as unknown as typeof fetch;
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('renders an expand affordance on parent categories with children', () => {
        render(<SankeyChart data={data} period={period} />);

        expect(
            screen.getByRole('button', { name: 'Expand Food' }),
        ).toBeInTheDocument();
    });

    it('expands a category into its subcategories without replacing the chart', async () => {
        render(<SankeyChart data={data} period={period} />);

        fireEvent.click(screen.getByRole('button', { name: 'Expand Food' }));

        await waitFor(() => {
            expect(screen.getByText('Groceries')).toBeInTheDocument();
        });

        expect(screen.getByText('Other groceries')).toBeInTheDocument();
        // The original chart is untouched: center + parent nodes remain.
        expect(screen.getByText('Cashflow')).toBeInTheDocument();
        expect(screen.getByText('Food')).toBeInTheDocument();
        expect(screen.getByText('Rent')).toBeInTheDocument();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('parent=food'),
        );
    });

    it('truncates long category names with an ellipsis and keeps the full name as a title', () => {
        const longName = 'Sports, and sport goods and equipment rentals';
        render(
            <SankeyChart
                data={{
                    ...data,
                    expense_categories: [
                        {
                            category: category('long', longName),
                            category_id: 'long',
                            amount: 500,
                        },
                    ],
                    total_expense: 500,
                }}
                period={period}
            />,
        );

        // The full name stays in the DOM (with a title for hover); CSS handles
        // the visual ellipsis via the `truncate` utility.
        const label = screen.getByTitle(longName);
        expect(label).toHaveTextContent(longName);
        expect(label).toHaveClass('truncate');
    });

    it('collapses any other expanded category so only one stays open', async () => {
        const multiParent: SankeyData = {
            ...data,
            expense_categories: [
                {
                    category: category('food', 'Food'),
                    category_id: 'food',
                    amount: 310,
                    has_children: true,
                },
                {
                    category: category('rent', 'Rent'),
                    category_id: 'rent',
                    amount: 500,
                    has_children: true,
                },
            ],
        };

        const rentChildren: SankeyData = {
            income_categories: [],
            expense_categories: [
                {
                    category: category('mortgage', 'Mortgage'),
                    category_id: 'mortgage',
                    amount: 500,
                },
            ],
            total_income: 0,
            total_expense: 500,
        };

        global.fetch = vi.fn().mockImplementation((url: string) => {
            const json = url.includes('parent=rent')
                ? rentChildren
                : foodChildren;

            return Promise.resolve({ json: async () => json });
        }) as unknown as typeof fetch;

        render(<SankeyChart data={multiParent} period={period} />);

        fireEvent.click(screen.getByRole('button', { name: 'Expand Food' }));

        await waitFor(() => {
            expect(screen.getByText('Groceries')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Expand Rent' }));

        await waitFor(() => {
            expect(screen.getByText('Mortgage')).toBeInTheDocument();
        });

        // Food's subcategories are gone now that Rent is open.
        expect(screen.queryByText('Groceries')).not.toBeInTheDocument();
    });

    it('collapses an expanded category when toggled again', async () => {
        render(<SankeyChart data={data} period={period} />);

        fireEvent.click(screen.getByRole('button', { name: 'Expand Food' }));

        await waitFor(() => {
            expect(screen.getByText('Groceries')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('button', { name: 'Collapse Food' }));

        await waitFor(() => {
            expect(screen.queryByText('Groceries')).not.toBeInTheDocument();
        });
    });
});
