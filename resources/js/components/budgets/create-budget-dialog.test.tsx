import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { CreateBudgetDialog } from './create-budget-dialog';

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    Element.prototype.scrollIntoView = vi.fn();
    Element.prototype.hasPointerCapture = vi.fn(() => false);
    Element.prototype.setPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
});

const post = vi.fn();

const pageProps = vi.hoisted(() => ({
    current: {
        categories: [] as unknown[],
        labels: [] as unknown[],
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
    MultiSelect: ({
        placeholder,
        options,
    }: {
        placeholder: string;
        options: { value: string; label: string }[];
    }) => (
        <div>
            {placeholder}
            {options.map((option) => (
                <span key={option.value} data-testid="multi-select-option">
                    {option.label}
                </span>
            ))}
        </div>
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

    it('does not offer archived labels', () => {
        pageProps.current = {
            categories: [],
            labels: [
                { id: 'l-1', name: 'Travel', color: 'blue', archived_at: null },
                {
                    id: 'l-2',
                    name: 'Old flat',
                    color: 'red',
                    archived_at: '2026-01-01T00:00:00Z',
                },
            ],
        };

        openDialog();

        expect(
            screen
                .getAllByTestId('multi-select-option')
                .map((option) => option.textContent),
        ).toEqual(['Travel']);
    });

    it('hides category and label selectors when catch-all is enabled', () => {
        openDialog();

        fireEvent.click(screen.getByLabelText('Catch-all budget'));

        expect(screen.queryByText('Select categories')).not.toBeInTheDocument();
        expect(screen.queryByText('Select labels')).not.toBeInTheDocument();
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
