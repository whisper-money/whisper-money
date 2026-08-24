import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { CreateBudgetDialog } from './create-budget-dialog';

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
    usePage: () => ({ props: { categories: [], labels: [] } }),
}));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    store: () => ({ url: '/budgets' }),
}));

vi.mock('@/components/ui/multi-select', () => ({
    MultiSelect: ({ placeholder }: { placeholder: string }) => (
        <div>{placeholder}</div>
    ),
}));

vi.mock('@/components/ui/amount-input', () => ({
    AmountInput: () => <input aria-label="Allocated Amount" />,
}));

function openDialog() {
    render(<CreateBudgetDialog />);
    fireEvent.click(screen.getByText('Create Budget'));
}

describe('CreateBudgetDialog', () => {
    it('shows category and label selectors by default', () => {
        openDialog();

        expect(screen.getByText('Select categories')).toBeInTheDocument();
        expect(screen.getByText('Select labels')).toBeInTheDocument();
    });

    it('hides category and label selectors when catch-all is enabled', () => {
        openDialog();

        fireEvent.click(screen.getByLabelText('Catch-all budget'));

        expect(screen.queryByText('Select categories')).not.toBeInTheDocument();
        expect(screen.queryByText('Select labels')).not.toBeInTheDocument();
    });
});
