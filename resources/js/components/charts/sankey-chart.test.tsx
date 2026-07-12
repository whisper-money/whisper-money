import { SankeyData } from '@/hooks/use-cashflow-data';
import { Category } from '@/types/category';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import * as React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SankeyChart } from './sankey-chart';

// ResponsiveContainer measures its DOM parent, which is 0x0 in jsdom, so the
// chart never lays out. Clone the child chart with a fixed size instead —
// Sankey reports whatever width/height it receives as props.
vi.mock('recharts', async (importOriginal) => {
    const actual = (await importOriginal()) as typeof import('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({
            children,
        }: {
            children: React.ReactElement<{ width?: number; height?: number }>;
        }) => React.cloneElement(children, { width: 800, height: 400 }),
    };
});

const privacyMode = {
    isPrivacyModeEnabled: false,
    togglePrivacyMode: vi.fn(),
    setPrivacyMode: vi.fn(),
};

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: vi.fn(() => privacyMode),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
}));

vi.mock('@/hooks/use-chart-color-scheme', () => ({
    useChartColors: () => ({
        cashflowIncomeColor: '#22c55e',
        cashflowExpenseColor: '#ef4444',
        categoryBarColor: (color: string) => color || '#999',
    }),
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: () => ({ url: '/transactions' }),
}));

import { usePrivacyMode } from '@/contexts/privacy-mode-context';

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
            category: category('eating-out', 'Eating out'),
            category_id: 'eating-out',
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

    it('renders income, center and expense nodes', () => {
        render(<SankeyChart data={data} period={period} />);

        expect(screen.getByText('Salary')).toBeInTheDocument();
        expect(screen.getByText('Net')).toBeInTheDocument();
        expect(screen.getByText('Food')).toBeInTheDocument();
        expect(screen.getByText('Rent')).toBeInTheDocument();
    });

    it('shows an empty state when there is no cashflow', () => {
        render(
            <SankeyChart
                data={{
                    income_categories: [],
                    expense_categories: [],
                    total_income: 0,
                    total_expense: 0,
                }}
                period={period}
            />,
        );

        expect(
            screen.getByText('No cashflow data for this period'),
        ).toBeInTheDocument();
    });

    it('groups small categories into an "Other" node', () => {
        const many = Array.from({ length: 8 }, (_, i) => ({
            category: category(`c${i}`, `Category ${i}`),
            category_id: `c${i}`,
            // First three are large, the rest are tiny (<3%).
            amount: i < 3 ? 300 : 5,
        }));

        render(
            <SankeyChart
                data={{
                    income_categories: [
                        {
                            category: category('salary', 'Salary'),
                            category_id: 'salary',
                            amount: 925,
                        },
                    ],
                    expense_categories: many,
                    total_income: 925,
                    total_expense: 925,
                }}
                period={period}
            />,
        );

        expect(screen.getByText('Other')).toBeInTheDocument();
        // The tiny categories are folded away, not rendered individually.
        expect(screen.queryByText('Category 7')).not.toBeInTheDocument();
    });

    it('shows an expand affordance on expense categories with children', () => {
        render(<SankeyChart data={data} period={period} />);

        expect(
            screen.getByRole('button', { name: 'Expand Food' }),
        ).toBeInTheDocument();
        // Rent has no children, so it links straight to its transactions.
        expect(
            screen.getByRole('link', { name: 'View Rent transactions' }),
        ).toBeInTheDocument();
    });

    it('expands an expense category into its subcategories on click', async () => {
        render(<SankeyChart data={data} period={period} />);

        fireEvent.click(screen.getByRole('button', { name: 'Expand Food' }));

        await waitFor(() => {
            expect(screen.getByText('Groceries')).toBeInTheDocument();
        });

        expect(screen.getByText('Eating out')).toBeInTheDocument();
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('parent=food'),
        );
        // The rest of the chart stays in place.
        expect(screen.getByText('Rent')).toBeInTheDocument();
        expect(screen.getByText('Net')).toBeInTheDocument();
    });

    it('masks amounts in privacy mode', () => {
        vi.mocked(usePrivacyMode).mockReturnValue({
            ...privacyMode,
            isPrivacyModeEnabled: true,
        });

        render(<SankeyChart data={data} period={period} />);

        // No raw digits leak into the labels when privacy mode is on.
        expect(screen.getByText('Salary')).toBeInTheDocument();
        expect(document.body.textContent).not.toMatch(/1,?000/);
    });
});
