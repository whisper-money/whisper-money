import { fireEvent, render, screen, within } from '@testing-library/react';
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

function choosePeriodType(label: string) {
    fireEvent.keyDown(screen.getByLabelText('Period Type'), {
        key: 'ArrowDown',
    });
    fireEvent.click(screen.getByRole('option', { name: label }));
}

describe('CreateBudgetDialog', () => {
    beforeEach(() => {
        pageProps.current = {
            categories: [],
            labels: [],
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

    it('creates a zero-allocation catch-all budget', () => {
        openDialog();

        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Budget Name'), {
            target: { value: 'No allocation' },
        });
        fireEvent.click(within(dialog).getByLabelText('Catch-all budget'));

        const submitButton = within(dialog).getByRole('button', {
            name: 'Create Budget',
        });
        expect(submitButton).toBeEnabled();

        fireEvent.click(submitButton);

        expect(post).toHaveBeenCalledWith(
            '/budgets',
            expect.objectContaining({
                name: 'No allocation',
                allocated_amount: 0,
                is_catch_all: true,
            }),
            expect.any(Object),
        );
    });

    it('offers the expense type, variable by default', () => {
        openDialog();

        expect(screen.getByLabelText('Expense Type')).toHaveTextContent(
            'Variable expense',
        );
    });

    it('does not offer an expense type for a catch-all budget', () => {
        openDialog();

        fireEvent.click(screen.getByLabelText('Catch-all budget'));

        expect(screen.queryByLabelText('Expense Type')).not.toBeInTheDocument();
    });

    it('sends the default variable expense type with a new budget', () => {
        openDialog();

        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByLabelText('Budget Name'), {
            target: { value: 'Everything else' },
        });
        fireEvent.click(within(dialog).getByLabelText('Catch-all budget'));
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Create Budget' }),
        );

        expect(post).toHaveBeenLastCalledWith(
            '/budgets',
            expect.objectContaining({ expense_type: 'variable' }),
            expect.any(Object),
        );
    });

    it.each(['Quarterly', 'Yearly'])(
        'drops the start day for a %s budget and sends day 1',
        (label) => {
            openDialog();
            fireEvent.change(screen.getByLabelText('Start Day of Month'), {
                target: { value: '15' },
            });

            choosePeriodType(label);

            expect(
                screen.queryByLabelText(/Start Day/),
            ).not.toBeInTheDocument();

            fireEvent.change(screen.getByLabelText('Budget Name'), {
                target: { value: 'Car maintenance' },
            });
            fireEvent.click(screen.getByLabelText('Catch-all budget'));
            fireEvent.submit(
                screen.getByLabelText('Budget Name').closest('form')!,
            );

            expect(post).toHaveBeenCalledWith(
                '/budgets',
                expect.objectContaining({
                    period_type: label.toLowerCase(),
                    period_start_day: 1,
                }),
                expect.anything(),
            );
        },
    );
});
