import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ExpensesCard } from './expenses-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
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
        fixed_expense: 90000,
        variable_expense: 30000,
        general_expense: 12000,
        ...overrides,
    };
}

describe('ExpensesCard', () => {
    it('shows the total expense, its share of income and the three blocks', () => {
        render(<ExpensesCard current={summary()} onViewDetails={vi.fn()} />);

        expect(screen.getByText('132000')).toBeTruthy();
        expect(screen.getByText('66.0%')).toBeTruthy();
        expect(screen.getByText('90000')).toBeTruthy();
        expect(screen.getByText('30000')).toBeTruthy();
        expect(screen.getByText('12000')).toBeTruthy();
        expect(screen.getByText('Fixed budgets')).toBeTruthy();
        expect(screen.getByText('Variable budgets')).toBeTruthy();
        expect(screen.getByText('No budget')).toBeTruthy();
        expect(screen.getByRole('img').getAttribute('aria-label')).toBe(
            'Expense split: 68% fixed, 23% variable, 9% general',
        );
    });

    it('draws each block as wide as its share of the expense', () => {
        const { container } = render(
            <ExpensesCard current={summary()} onViewDetails={vi.fn()} />,
        );

        const fixed = container.querySelector<HTMLElement>(
            '[data-segment="fixed"]',
        );
        expect(fixed?.style.width).toBe(`${(90000 / 132000) * 100}%`);
    });

    it('leaves a block that went negative out of the bar', () => {
        const { container } = render(
            <ExpensesCard
                current={summary({
                    expense: 2000,
                    fixed_expense: -10000,
                    variable_expense: 8000,
                    general_expense: 4000,
                })}
                onViewDetails={vi.fn()}
            />,
        );

        expect(container.querySelector('[data-segment="fixed"]')).toBeNull();
        expect(
            container.querySelector<HTMLElement>('[data-segment="variable"]')
                ?.style.width,
        ).toBe(`${(8000 / 12000) * 100}%`);
    });

    it('reads the whole expense as general when no budget split came back', () => {
        render(
            <ExpensesCard
                current={summary({
                    fixed_expense: undefined,
                    variable_expense: undefined,
                    general_expense: undefined,
                })}
                onViewDetails={vi.fn()}
            />,
        );

        expect(screen.getAllByText('132000')).toHaveLength(2);
        expect(screen.getAllByText('0')).toHaveLength(2);
    });

    it('draws an empty bar and 0% with no expense or income', () => {
        const { container } = render(
            <ExpensesCard
                current={summary({
                    income: 0,
                    expense: 0,
                    fixed_expense: 0,
                    variable_expense: 0,
                    general_expense: 0,
                })}
                onViewDetails={vi.fn()}
            />,
        );

        expect(screen.getByText('0.0%')).toBeTruthy();
        expect(container.querySelector('[data-segment]')).toBeNull();
    });

    it('links a block to its transactions only when given an href', () => {
        const { rerender } = render(
            <ExpensesCard current={summary()} onViewDetails={vi.fn()} />,
        );

        expect(screen.queryAllByRole('link')).toHaveLength(0);

        rerender(
            <ExpensesCard
                current={summary()}
                onViewDetails={vi.fn()}
                hrefs={{ fixed: '/transactions?block=fixed' }}
            />,
        );

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toBe('/transactions?block=fixed');
    });

    it('opens the details from its button', () => {
        const onViewDetails = vi.fn();
        render(
            <ExpensesCard current={summary()} onViewDetails={onViewDetails} />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'View details' }));

        expect(onViewDetails).toHaveBeenCalledOnce();
    });

    it('shows a placeholder while loading', () => {
        render(
            <ExpensesCard
                current={summary()}
                loading
                onViewDetails={vi.fn()}
            />,
        );

        expect(screen.getByText('Expenses (cashflow)')).toBeTruthy();
        expect(screen.queryByText('132000')).toBeNull();
    });
});
