import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { CreateBudgetDialog } from './create-budget-dialog';

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

const post = vi.fn();

const pageProps = vi.hoisted(() => ({
    current: {
        categories: [],
        labels: [],
        features: { cashflowAvailableFree: false },
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
    usePage: () => ({ props: pageProps.current }),
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

function enableCashflow() {
    pageProps.current = {
        ...pageProps.current,
        features: { cashflowAvailableFree: true },
    };
}

describe('CreateBudgetDialog', () => {
    beforeEach(() => {
        pageProps.current = {
            categories: [],
            labels: [],
            features: { cashflowAvailableFree: false },
        };
    });

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

    it('hides the expense type while cashflow is not available', () => {
        openDialog();

        expect(screen.queryByLabelText('Expense Type')).not.toBeInTheDocument();
    });

    it('offers the expense type, variable by default, when cashflow is available', () => {
        enableCashflow();
        openDialog();

        expect(screen.getByLabelText('Expense Type')).toHaveTextContent(
            'Variable expense',
        );
    });

    it('does not offer an expense type for a catch-all budget', () => {
        enableCashflow();
        openDialog();

        fireEvent.click(screen.getByLabelText('Catch-all budget'));

        expect(screen.queryByLabelText('Expense Type')).not.toBeInTheDocument();
    });
});
