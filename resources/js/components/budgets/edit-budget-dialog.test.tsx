import type { Budget } from '@/types/budget';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EditBudgetDialog } from './edit-budget-dialog';

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: vi.fn(),
    },
}));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    update: () => ({ url: '/budgets/budget-1' }),
}));

vi.mock('@/components/ui/amount-input', () => ({
    AmountInput: () => <input aria-label="Allocated Amount" />,
}));

function makeBudget(): Budget {
    return {
        id: 'budget-1',
        user_id: 'user-1',
        name: 'Monthly budget',
        period_type: 'monthly',
        period_start_day: 1,
        categories: [],
        labels: [],
        rollover_type: 'carry_over',
        is_catch_all: false,
        created_at: '2026-05-26T00:00:00.000000Z',
        updated_at: '2026-05-26T00:00:00.000000Z',
        deleted_at: null,
    };
}

describe('EditBudgetDialog', () => {
    it('explains locked budget period and carry-over fields', () => {
        render(
            <EditBudgetDialog
                budget={makeBudget()}
                currentPeriod={{ allocated_amount: 10000 }}
                open={true}
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText(
                'Period and carry-over settings cannot be changed after a budget is created because budgets are calculated historically. If you need different settings, delete this budget and create a new one.',
            ),
        ).toBeInTheDocument();
    });
});
