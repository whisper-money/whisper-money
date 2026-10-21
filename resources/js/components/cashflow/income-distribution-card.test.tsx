import { type CashflowSummary } from '@/hooks/use-cashflow-data';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { IncomeDistributionCard } from './income-distribution-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({
        amountInCents,
        className,
    }: {
        amountInCents: number;
        className?: string;
    }) => <span className={className}>{amountInCents}</span>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: { url: string }; children: string }) => (
        <a href={href.url}>{children}</a>
    ),
}));

function summary(overrides: Partial<CashflowSummary> = {}): CashflowSummary {
    return {
        income: 200000,
        expense: 132000,
        net: 68000,
        savings_rate: 0,
        savings: 25000,
        investments: 25000,
        fixed_expense: 94000,
        variable_expense: 38000,
        reserved: 13000,
        budgets: [
            {
                budget_id: 'b1',
                budget_period_id: 'p1',
                name: 'Groceries',
                start_date: '2026-10-01',
                end_date: '2026-10-31',
                allocated: 25000,
                spent: 18000,
                margin: 7000,
            },
            {
                budget_id: 'b2',
                budget_period_id: 'p2',
                name: 'Leisure',
                start_date: '2026-10-01',
                end_date: '2026-10-31',
                allocated: 20000,
                spent: 14000,
                margin: 6000,
            },
            {
                budget_id: 'b3',
                budget_period_id: 'p3',
                name: 'Clothes',
                start_date: '2026-10-01',
                end_date: '2026-10-31',
                allocated: 5000,
                spent: 6000,
                margin: -1000,
            },
        ],
        ...overrides,
    };
}

describe('IncomeDistributionCard', () => {
    it('shows the bar, the legend, the equation and the budget margins', () => {
        render(<IncomeDistributionCard summary={summary()} currency="EUR" />);

        expect(screen.getByText('Income distribution')).toBeTruthy();
        expect(screen.getByRole('img').getAttribute('aria-label')).toBe(
            'Income distribution: Fixed expenses 47%, Variable expenses 19%, Saved (cashflow) 13%, Invested 13%, Reserved 7%, Free (cashflow) 3%',
        );
        expect(screen.getByText('Fixed expenses')).toBeTruthy();
        expect(screen.getByText('94000')).toBeTruthy();
        expect(screen.getByText('From available to free')).toBeTruthy();

        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row');
        // header, three budgets, the total
        expect(rows).toHaveLength(5);
        expect(within(rows[3]).getByText('-1000').className).toContain(
            'text-red-600',
        );
        expect(within(rows[4]).getByText('Reserved')).toBeTruthy();
        expect(within(rows[4]).getByText('13000')).toBeTruthy();
        expect(
            screen.getByText(/doesn't reduce the others' reserve/),
        ).toBeTruthy();
    });

    it('points to budgets instead of an empty table', () => {
        render(
            <IncomeDistributionCard
                summary={summary({ reserved: 0, budgets: [] })}
                currency="EUR"
            />,
        );

        expect(screen.queryByRole('table')).toBeNull();
        expect(
            screen.getByText(/No budget is running in this period/),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Go to budgets' })
                .getAttribute('href'),
        ).toBe('/budgets');
    });

    it('shows a negative free in red when more was allocated than earned', () => {
        render(
            <IncomeDistributionCard
                summary={summary({
                    income: 100000,
                    net: -32000,
                    reserved: 13000,
                })}
                currency="EUR"
            />,
        );

        // Available −82000, minus 13000 reserved.
        const free = screen.getAllByText('-95000');
        expect(free.length).toBeGreaterThan(0);
        free.forEach((amount) =>
            expect(amount.className).toContain('text-red-600'),
        );
        expect(screen.getByRole('img').getAttribute('aria-label')).not.toMatch(
            /Free/,
        );
        expect(screen.queryByText(/^Available ·/)).toBeNull();
    });

    it('shows a placeholder while loading', () => {
        const { container } = render(
            <IncomeDistributionCard summary={summary()} loading />,
        );

        expect(screen.getByText('Income distribution')).toBeTruthy();
        expect(container.querySelector('.animate-pulse')).toBeTruthy();
        expect(screen.queryByRole('img')).toBeNull();
    });
});
