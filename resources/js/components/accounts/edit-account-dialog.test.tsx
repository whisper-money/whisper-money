import type { Account } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EditAccountDialog } from './edit-account-dialog';

vi.mock('@inertiajs/react', () => ({
    router: {
        patch: vi.fn(),
        visit: vi.fn(),
    },
}));

vi.mock('@/actions/App/Http/Controllers/Settings/AccountController', () => ({
    update: {
        url: (id: string) => `/settings/accounts/${id}`,
    },
}));

vi.mock('@/actions/App/Http/Controllers/Settings/BankController', () => ({
    store: {
        url: () => '/settings/banks',
    },
}));

vi.mock('./account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

vi.mock('./delete-account-dialog', () => ({
    DeleteAccountDialog: ({
        open,
        redirectTo,
    }: {
        open: boolean;
        redirectTo?: string;
    }) =>
        open ? (
            <div
                data-testid="delete-account-dialog"
                data-redirect-to={redirectTo}
            >
                Delete Account confirmation
            </div>
        ) : null,
}));

function makeAccount(): Account {
    return {
        id: 'account-1',
        name: 'Checking',
        name_iv: null,
        encrypted: false,
        bank: {
            id: 'bank-1',
            user_id: null,
            name: 'Test Bank',
            logo: null,
        },
        type: 'checking',
        currency_code: 'USD',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
    };
}

describe('EditAccountDialog', () => {
    it('shows account deletion inside edit modal with confirmation dialog', () => {
        render(
            <EditAccountDialog
                account={makeAccount()}
                open={true}
                onOpenChange={vi.fn()}
                deleteRedirectTo="/accounts"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Delete account' }));

        expect(
            screen.getByTestId('delete-account-dialog').textContent,
        ).toContain('Delete Account confirmation');
        expect(
            screen
                .getByTestId('delete-account-dialog')
                .getAttribute('data-redirect-to'),
        ).toBe('/accounts');
    });

    it('hides account deletion when no delete redirect is provided', () => {
        render(
            <EditAccountDialog
                account={makeAccount()}
                open={true}
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Delete account' }),
        ).toBeNull();
    });
});
