import type { Budget } from '@/types/budget';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
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

vi.mock('@/components/ui/multi-select', () => ({
    MultiSelect: ({
        id,
        selected,
        onChange,
    }: {
        id: string;
        selected: string[];
        onChange: (value: string[]) => void;
    }) => (
        <button
            type="button"
            aria-label={id}
            onClick={() =>
                onChange(id === 'categories' ? ['category-2'] : ['label-1'])
            }
        >
            {selected.join(',')}
        </button>
    ),
}));

const categories = [
    { id: 'category-1', name: 'Food', color: 'blue', icon: 'Pizza' },
    { id: 'category-2', name: 'Travel', color: 'green', icon: 'Plane' },
] as Category[];
const labels = [{ id: 'label-1', name: 'Holiday', color: 'purple' }] as Label[];

function makeBudget(overrides: Partial<Budget> = {}): Budget {
    return {
        id: 'budget-1',
        user_id: 'user-1',
        name: 'Monthly budget',
        period_type: 'monthly',
        period_start_day: 1,
        categories: [categories[0]],
        labels: [],
        rollover_type: 'carry_over',
        expense_type: 'fixed',
        notify_on_new_transaction: false,
        notify_on_close_to_limit: false,
        notify_on_over_limit: false,
        is_catch_all: false,
        position: null,
        archived_at: null,
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
            categories={categories}
            labels={labels}
            open={true}
            onOpenChange={vi.fn()}
        />,
    );
}

describe('EditBudgetDialog', () => {
    it('explains locked budget period and carry-over fields', () => {
        renderDialog();

        expect(
            screen.getByText(
                'Period and carry-over settings stay locked to preserve historical period boundaries. Categories and labels can be changed without rewriting closed periods.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Allocated Amount'),
        ).not.toBeInTheDocument();
    });

    it('submits replacement category and label tracking', () => {
        renderDialog();

        fireEvent.click(screen.getByLabelText('categories'));
        fireEvent.click(screen.getByLabelText('labels'));
        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/budgets/budget-1',
            {
                name: 'Monthly budget',
                category_ids: ['category-2'],
                label_ids: ['label-1'],
                expense_type: 'fixed',
            },
            expect.any(Object),
        );
    });

    it('shows the budget expense type', () => {
        renderDialog();

        expect(screen.getByLabelText('Expense Type')).toHaveTextContent(
            'Fixed expense',
        );
    });

    it('does not offer an expense type for a catch-all budget', () => {
        renderDialog(makeBudget({ is_catch_all: true }));

        expect(screen.queryByLabelText('Expense Type')).not.toBeInTheDocument();
    });

    it('does not send an expense type for a catch-all budget', () => {
        renderDialog(makeBudget({ is_catch_all: true, categories: [] }));
        fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }));

        expect(router.patch).toHaveBeenLastCalledWith(
            '/budgets/budget-1',
            { name: 'Monthly budget' },
            expect.any(Object),
        );
    });

    it('hides the start day of a quarterly budget, which follows the calendar', () => {
        renderDialog(
            makeBudget({ period_type: 'quarterly', period_start_day: 1 }),
        );

        expect(screen.getByLabelText('Period Type')).toHaveTextContent(
            'Quarterly',
        );
        expect(screen.queryByLabelText(/Start Day/)).not.toBeInTheDocument();
    });
});
