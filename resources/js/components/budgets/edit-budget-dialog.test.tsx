import type { Budget } from '@/types/budget';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EditBudgetDialog } from './edit-budget-dialog';

const pageProps = vi.hoisted(() => ({
    current: { features: { cashflowAvailableFree: false } },
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: vi.fn(),
    },
    usePage: () => ({ props: pageProps.current }),
}));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    update: () => ({ url: '/budgets/budget-1' }),
}));

vi.mock('@/components/ui/amount-input', () => ({
    AmountInput: () => <input aria-label="Allocated Amount" />,
}));

function makeBudget(overrides: Partial<Budget> = {}): Budget {
    return {
        id: 'budget-1',
        user_id: 'user-1',
        name: 'Monthly budget',
        period_type: 'monthly',
        period_start_day: 1,
        categories: [],
        labels: [],
        rollover_type: 'carry_over',
        expense_type: 'fixed',
        is_catch_all: false,
        created_at: '2026-05-26T00:00:00.000000Z',
        updated_at: '2026-05-26T00:00:00.000000Z',
        deleted_at: null,
        ...overrides,
    };
}

function renderDialog(budget: Budget = makeBudget()) {
    render(
        <EditBudgetDialog
            budget={budget}
            currentPeriod={{ allocated_amount: 10000 }}
            open={true}
            onOpenChange={vi.fn()}
        />,
    );
}

describe('EditBudgetDialog', () => {
    beforeEach(() => {
        pageProps.current = { features: { cashflowAvailableFree: false } };
    });

    it('explains locked budget period and carry-over fields', () => {
        renderDialog();

        expect(
            screen.getByText(
                'Period and carry-over settings cannot be changed after a budget is created because budgets are calculated historically. If you need different settings, delete this budget and create a new one.',
            ),
        ).toBeInTheDocument();
    });

    it('hides the expense type while cashflow is not available', () => {
        renderDialog();

        expect(screen.queryByLabelText('Expense Type')).not.toBeInTheDocument();
    });

    it('shows the budget expense type when cashflow is available', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        renderDialog();

        expect(screen.getByLabelText('Expense Type')).toHaveTextContent(
            'Fixed expense',
        );
    });

    it('does not offer an expense type for a catch-all budget', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        renderDialog(makeBudget({ is_catch_all: true }));

        expect(screen.queryByLabelText('Expense Type')).not.toBeInTheDocument();
    });
});
