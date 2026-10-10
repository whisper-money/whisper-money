import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CreateSavingsGoalDialog } from './create-savings-goal-dialog';

const reload = vi.fn();
let props: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
        reload: (...args: unknown[]) => reload(...args),
    },
    usePage: () => ({ props }),
}));

vi.mock('@/actions/App/Http/Controllers/SavingsGoalController', () => ({
    store: () => ({ url: '/savings-goals' }),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

function openMonthly() {
    render(
        <CreateSavingsGoalDialog
            open={true}
            onOpenChange={() => {}}
            currencyCode="EUR"
        />,
    );
    fireEvent.click(screen.getByText('Monthly (recurring)'));
}

describe('CreateSavingsGoalDialog', () => {
    beforeEach(() => {
        reload.mockClear();
        props = {};
    });

    it('asks for the auto-tag accounts once a monthly goal is picked', () => {
        openMonthly();

        expect(reload).toHaveBeenCalledWith({ only: ['autoTagAccounts'] });
    });

    it('leaves the auto-tag option off and says what it would do', () => {
        props = {
            autoTagAccounts: [{ id: 'a1', name: 'Rainy day', bank: null }],
        };

        openMonthly();

        expect(
            screen.getByRole('checkbox', {
                name: /Tag contributions automatically/,
            }),
        ).toHaveAttribute('aria-checked', 'false');
        expect(
            screen.getByText(/interest and refunds included/),
        ).toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });

    it('picks a free account and marks the ones another goal already uses', () => {
        props = {
            autoTagAccounts: [
                { id: 'a1', name: 'Rainy day', bank: null, used_by: 'Fund' },
                { id: 'a2', name: 'Holidays', bank: null, used_by: null },
            ],
        };

        openMonthly();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: /Tag contributions automatically/,
            }),
        );

        expect(screen.getByRole('combobox')).toHaveTextContent('Holidays');
    });
});
