import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import AccountShow from './Show';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload: vi.fn() },
    Deferred: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/actions/App/Http/Controllers/AccountController', () => ({
    index: () => ({ url: '/accounts' }),
    show: { url: (id: string) => `/accounts/${id}` },
}));

vi.mock('@/actions/App/Http/Controllers/LoanDetailController', () => ({
    update: { form: () => ({ action: '/loan-detail', method: 'patch' }) },
}));

vi.mock('@/actions/App/Http/Controllers/RealEstateDetailController', () => ({
    update: {
        form: () => ({ action: '/real-estate-detail', method: 'patch' }),
    },
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/accounts/account-balance-chart', () => ({
    AccountBalanceChart: () => null,
}));

vi.mock('@/components/accounts/balances-modal', () => ({
    BalancesModal: () => null,
}));

vi.mock('@/components/accounts/edit-account-dialog', () => ({
    EditAccountDialog: () => null,
}));

vi.mock('@/components/accounts/edit-loan-detail-dialog', () => ({
    EditLoanDetailDialog: () => null,
}));

vi.mock('@/components/accounts/import-balances-drawer', () => ({
    ImportBalancesDrawer: () => null,
}));

vi.mock('@/components/accounts/update-balance-dialog', () => ({
    UpdateBalanceDialog: () => null,
}));

const editTransactionDialog = vi.fn();

vi.mock('@/components/transactions/edit-transaction-dialog', () => ({
    EditTransactionDialog: (props: Record<string, unknown>) => {
        editTransactionDialog(props);
        return null;
    },
}));

vi.mock('@/components/transactions/transaction-list', () => ({
    TransactionList: () => null,
}));

vi.mock('@/components/bank-logo', () => ({
    BankLogo: () => null,
}));

vi.mock('@/components/mobile-back-button', () => ({
    MobileBackButton: () => null,
}));

const baseAccount = {
    id: 'account-1',
    name: 'Checking',
    name_iv: null,
    encrypted: false,
    bank: null,
    type: 'checking' as const,
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

const renderPage = (account = baseAccount) =>
    render(
        <AccountShow
            account={account}
            categories={[]}
            accounts={[account]}
            banks={[]}
            labels={[]}
            automationRules={[]}
        />,
    );

describe('AccountShow', () => {
    it('opens create transaction dialog for disconnected transactional accounts', () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', { name: 'Add transaction' }),
        );

        expect(editTransactionDialog).toHaveBeenLastCalledWith(
            expect.objectContaining({
                open: true,
                initialAccountId: 'account-1',
                mode: 'create',
            }),
        );
    });

    it('reloads only the deferred transactions prop after a transaction is created', () => {
        renderPage();

        const { onSuccess } = editTransactionDialog.mock.calls.at(-1)![0] as {
            onSuccess: () => void;
        };
        act(() => onSuccess());

        expect(router.reload).toHaveBeenCalledWith({ only: ['transactions'] });
    });

    it('hides transaction action for connected accounts', () => {
        renderPage({
            ...baseAccount,
            banking_connection_id: 'connection-1',
        });

        expect(
            screen.queryByRole('button', { name: 'Transaction' }),
        ).not.toBeInTheDocument();
    });
});
