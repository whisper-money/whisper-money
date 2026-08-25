import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BudgetsIndex, { budgetTypeFilterFromUrl } from './index';

const replace = vi.fn();
let pageUrl = '/budgets';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        replace: (...args: unknown[]) => replace(...args),
    },
    usePage: () => ({ url: pageUrl, props: {} }),
}));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    index: () => ({ url: '/budgets' }),
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));

vi.mock('@/components/budgets/budget-list-card', () => ({
    BudgetListCard: () => <div>budget-card</div>,
}));

vi.mock('@/components/savings-goals/savings-goal-list-card', () => ({
    SavingsGoalListCard: () => <div>goal-card</div>,
}));

vi.mock('@/components/budgets/create-budget-dialog', () => ({
    CreateBudgetDialog: () => <div>create-budget</div>,
}));

vi.mock('@/components/savings-goals/create-savings-goal-dialog', () => ({
    CreateSavingsGoalDialog: () => <div>create-goal</div>,
}));

const budget = { id: '1' } as never;
const goal = { id: '2' } as never;

function renderPage() {
    return render(
        <BudgetsIndex
            budgets={[budget]}
            savingsGoals={[goal]}
            savingsGoalsEnabled
            currencyCode="EUR"
        />,
    );
}

beforeEach(() => {
    replace.mockClear();
    pageUrl = '/budgets';
});

describe('budgetTypeFilterFromUrl', () => {
    it('parses the show query param', () => {
        expect(budgetTypeFilterFromUrl('/budgets')).toBe('all');
        expect(budgetTypeFilterFromUrl('/budgets?show=budgets')).toBe(
            'budgets',
        );
        expect(budgetTypeFilterFromUrl('/budgets?show=goals')).toBe('goals');
        expect(budgetTypeFilterFromUrl('/budgets?show=nonsense')).toBe('all');
    });
});

describe('BudgetsIndex filter', () => {
    it('shows both sections by default', () => {
        renderPage();

        expect(screen.getByText('budget-card')).toBeInTheDocument();
        expect(screen.getByText('goal-card')).toBeInTheDocument();
    });

    it('shows only savings goals and updates the URL when filtering', () => {
        renderPage();

        fireEvent.click(screen.getByRole('radio', { name: 'Savings Goals' }));

        expect(screen.queryByText('budget-card')).not.toBeInTheDocument();
        expect(screen.getByText('goal-card')).toBeInTheDocument();
        expect(replace).toHaveBeenCalledWith({
            url: '/budgets?show=goals',
            preserveScroll: true,
            preserveState: true,
        });
    });

    it('restores the filter from the URL on load', () => {
        pageUrl = '/budgets?show=budgets';
        renderPage();

        expect(screen.getByText('budget-card')).toBeInTheDocument();
        expect(screen.queryByText('goal-card')).not.toBeInTheDocument();
    });

    it('ignores the show param when savings goals are disabled', () => {
        pageUrl = '/budgets?show=goals';
        render(
            <BudgetsIndex
                budgets={[budget]}
                savingsGoalsEnabled={false}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('budget-card')).toBeInTheDocument();
    });

    it('falls back to all when the active filter is deselected', () => {
        renderPage();

        const budgetsItem = screen.getByRole('radio', { name: 'Budgets' });
        fireEvent.click(budgetsItem);
        fireEvent.click(budgetsItem);

        expect(screen.getByText('budget-card')).toBeInTheDocument();
        expect(screen.getByText('goal-card')).toBeInTheDocument();
        expect(replace).toHaveBeenLastCalledWith({
            url: '/budgets',
            preserveScroll: true,
            preserveState: true,
        });
    });
});
