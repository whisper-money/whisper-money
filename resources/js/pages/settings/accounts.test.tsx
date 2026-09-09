import type { Account } from '@/types/account';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import AccountsPage from './accounts';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { patch: vi.fn(), reload: vi.fn() },
}));

vi.mock('@/actions/App/Http/Controllers/AccountController', () => ({
    updateArchived: { url: (id: string) => `/accounts/${id}/archived` },
}));

vi.mock('@/actions/App/Http/Controllers/Settings/AccountController', () => ({
    index: { url: () => '/settings/accounts' },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/accounts/create-account-dialog', () => ({
    CreateAccountDialog: () => null,
}));

vi.mock('@/components/accounts/edit-account-dialog', () => ({
    EditAccountDialog: () => null,
}));

vi.mock('@/components/accounts/delete-account-dialog', () => ({
    DeleteAccountDialog: () => null,
}));

vi.mock('@/components/accounts/archive-account-dialog', () => ({
    ArchiveAccountDialog: () => null,
}));

function makeAccount(overrides: Partial<Account>): Account {
    return {
        id: 'account-1',
        name: 'Checking',
        name_iv: null,
        encrypted: false,
        bank: null,
        type: 'checking',
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
        ...overrides,
    };
}

function openRowMenu() {
    fireEvent.pointerDown(screen.getByLabelText('Open menu'), {
        button: 0,
        ctrlKey: false,
    });

    return screen.findByRole('menu');
}

describe('Accounts settings page', () => {
    it('offers deleting a manual account', async () => {
        render(<AccountsPage accounts={[makeAccount({})]} />);

        const menu = await openRowMenu();

        expect(
            within(menu).getByRole('menuitem', { name: 'Delete' }),
        ).toBeInTheDocument();
    });

    it('archives instead of deleting a connected account', async () => {
        render(
            <AccountsPage
                accounts={[makeAccount({ banking_connection_id: 'conn-1' })]}
            />,
        );

        const menu = await openRowMenu();

        expect(
            within(menu).queryByRole('menuitem', { name: 'Delete' }),
        ).toBeNull();
        expect(
            within(menu).getByRole('menuitem', { name: 'Archive' }),
        ).toBeInTheDocument();
    });
});
