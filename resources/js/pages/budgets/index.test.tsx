import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BudgetsIndex, { budgetTypeFilterFromUrl } from './index';

interface DialogMockProps {
    trigger?: React.ReactNode;
    onOpenChange?: (open: boolean) => void;
}

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

// The name is rendered in its own node so the archived tests can tell the
// cards apart while the rest keep matching on the plain marker.
vi.mock('@/components/budgets/budget-list-card', () => ({
    BudgetListCard: ({ budget }: { budget: { name?: string } }) => (
        <div>
            <span>budget-card</span>
            <span>{budget.name}</span>
        </div>
    ),
}));

vi.mock('@/components/savings-goals/savings-goal-list-card', () => ({
    SavingsGoalListCard: ({
        savingsGoal,
    }: {
        savingsGoal: { name?: string };
    }) => (
        <div>
            <span>goal-card</span>
            <span>{savingsGoal.name}</span>
        </div>
    ),
}));

// The page mounts each dialog up to three times: once as the header button,
// once as the list's trailing card, and once controlled at the bottom. Only the
// uncontrolled ones render a trigger, so the mocks mirror that.
vi.mock('@/components/budgets/create-budget-dialog', () => ({
    CreateBudgetDialog: ({ trigger, onOpenChange }: DialogMockProps) =>
        onOpenChange ? null : (
            <div>{trigger ? 'create-budget-header' : 'create-budget'}</div>
        ),
}));

vi.mock('@/components/savings-goals/create-savings-goal-dialog', () => ({
    CreateSavingsGoalDialog: ({ onOpenChange }: DialogMockProps) =>
        onOpenChange ? null : <div>create-goal</div>,
}));

vi.mock('@/components/shared/create-placeholder-card', () => ({
    CreatePlaceholderCard: ({ children }: { children: React.ReactNode }) => (
        <div>create-either: {children}</div>
    ),
}));

const budget = { id: '1', name: 'Groceries' } as never;
const goal = { id: '2', name: 'Japan trip' } as never;

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
    it('shows both types in one list by default', () => {
        renderPage();

        expect(screen.getByText('budget-card')).toBeInTheDocument();
        expect(screen.getByText('goal-card')).toBeInTheDocument();
    });

    it('does not split the list into per-type sections', () => {
        renderPage();

        expect(
            screen.queryByRole('heading', { name: 'Budgets' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('heading', { name: 'Savings Goals' }),
        ).not.toBeInTheDocument();
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

describe('BudgetsIndex create card', () => {
    it('offers both types when the list is unfiltered', () => {
        renderPage();

        expect(screen.getByText(/create-either/)).toBeInTheDocument();
    });

    it('creates the type being filtered on instead of asking again', () => {
        renderPage();

        fireEvent.click(screen.getByRole('radio', { name: 'Budgets' }));
        expect(screen.getByText('create-budget')).toBeInTheDocument();
        expect(screen.queryByText(/create-either/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: 'Savings Goals' }));
        expect(screen.getByText('create-goal')).toBeInTheDocument();
    });

    it('keeps the budget-only create card when savings goals are disabled', () => {
        render(
            <BudgetsIndex
                budgets={[budget]}
                savingsGoalsEnabled={false}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('create-budget')).toBeInTheDocument();
        expect(screen.queryByText(/create-either/)).not.toBeInTheDocument();
    });
});

const archivedBudget = {
    id: '3',
    name: 'Old budget',
    archived_at: '2026-03-01T00:00:00Z',
} as never;
const archivedGoal = {
    id: '4',
    name: 'Old goal',
    archived_at: '2026-03-01T00:00:00Z',
} as never;

function renderWithArchived(
    props: Partial<Parameters<typeof BudgetsIndex>[0]> = {},
) {
    render(
        <BudgetsIndex
            budgets={[budget, archivedBudget]}
            savingsGoals={[goal, archivedGoal]}
            savingsGoalsEnabled
            currencyCode="EUR"
            {...props}
        />,
    );
}

describe('BudgetsIndex archived section', () => {
    it('keeps archived budgets and goals out of the live list', () => {
        renderWithArchived();

        expect(screen.getByText('Groceries')).toBeInTheDocument();
        expect(screen.getByText('Japan trip')).toBeInTheDocument();
        expect(screen.queryByText('Old budget')).not.toBeInTheDocument();
        expect(screen.queryByText('Old goal')).not.toBeInTheDocument();
    });

    it('counts both types together and reveals them on click', () => {
        renderWithArchived();

        fireEvent.click(screen.getByText('Archived (2)'));

        expect(screen.getByText('Old budget')).toBeInTheDocument();
        expect(screen.getByText('Old goal')).toBeInTheDocument();
    });

    it('still shows archived budgets when savings goals are switched off', () => {
        renderWithArchived({ savingsGoals: [], savingsGoalsEnabled: false });

        fireEvent.click(screen.getByText('Archived (1)'));

        expect(screen.getByText('Old budget')).toBeInTheDocument();
    });

    it('narrows the archived section with the type filter', () => {
        renderWithArchived();

        fireEvent.click(screen.getByRole('radio', { name: 'Budgets' }));
        fireEvent.click(screen.getByText('Archived (1)'));

        expect(screen.getByText('Old budget')).toBeInTheDocument();
        expect(screen.queryByText('Old goal')).not.toBeInTheDocument();
    });

    it('hides the section entirely when nothing is archived', () => {
        renderWithArchived({ budgets: [budget], savingsGoals: [goal] });

        expect(screen.queryByText(/^Archived/)).not.toBeInTheDocument();
    });

    // The create card asks "is the list empty", and an archived-only list is
    // empty as far as the live grid is concerned.
    it('still offers the create card when everything is archived', () => {
        renderWithArchived({ budgets: [archivedBudget], savingsGoals: [] });

        expect(screen.getByText(/create-either/)).toBeInTheDocument();
    });
});
