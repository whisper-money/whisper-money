import { router } from '@inertiajs/react';
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
    props = { features: { monthlySavingsGoals: true }, ...props };
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
        vi.mocked(router.post).mockClear();
        props = {};
    });

    it('only creates one-off goals without the monthly goals flag', () => {
        props = { features: { monthlySavingsGoals: false } };

        render(
            <CreateSavingsGoalDialog
                open={true}
                onOpenChange={() => {}}
                currencyCode="EUR"
            />,
        );

        expect(screen.queryByText('Monthly (recurring)')).toBeNull();
        expect(screen.queryByRole('group', { name: 'Type' })).toBeNull();

        fireEvent.change(screen.getByLabelText('Goal Name'), {
            target: { value: 'New car' },
        });
        fireEvent.change(screen.getByLabelText('Target Amount'), {
            target: { value: '5000' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Create Savings Goal' }),
        );

        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            kind: 'one_off',
            target_amount: 500000,
        });
        expect(reload).not.toHaveBeenCalled();
    });

    it('asks for the auto-tag accounts once a monthly goal is picked', () => {
        openMonthly();

        expect(reload).toHaveBeenCalledWith({ only: ['autoTagAccounts'] });
    });

    it('leaves the auto-tag option off and says what it would do', () => {
        props = {
            autoTagAccounts: [
                { id: 'a1', name: 'Rainy day', bank: null, used_by: null },
            ],
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

    it('locks auto-tag and says why when no savings account is free', () => {
        props = {
            autoTagAccounts: [
                { id: 'a1', name: 'Rainy day', bank: null, used_by: 'Fund' },
            ],
        };

        openMonthly();

        expect(
            screen.getByRole('checkbox', {
                name: /Tag contributions automatically/,
            }),
        ).toBeDisabled();
        expect(
            screen.getByText(/There are no free savings accounts/),
        ).toBeInTheDocument();
    });

    it('says why when the space has no savings account at all', () => {
        props = { autoTagAccounts: [] };

        openMonthly();

        expect(
            screen.getByRole('checkbox', {
                name: /Tag contributions automatically/,
            }),
        ).toBeDisabled();
        expect(
            screen.getByText(/There are no savings accounts in this space/),
        ).toBeInTheDocument();
    });

    it('submits on the first click after typing the amount', () => {
        props = { autoTagAccounts: [] };

        openMonthly();
        fireEvent.change(screen.getByLabelText('Goal Name'), {
            target: { value: 'Rainy day' },
        });
        // Typed but never blurred: the button has to unlock from the typing.
        fireEvent.change(screen.getByLabelText('I want to save every month'), {
            target: { value: '300' },
        });
        const create = screen.getByRole('button', {
            name: 'Create Savings Goal',
        });

        expect(create).toBeEnabled();

        fireEvent.click(create);

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][1]).toMatchObject({
            monthly_target_amount: 30000,
            auto_tag: false,
            auto_tag_account_id: null,
        });
    });
});
