import { type ChartComputedData } from '@/components/accounts/account-balance-chart';
import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { useEffect, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AccountShow from './Show';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { reload: vi.fn(), patch: vi.fn() },
    usePage: () => ({ props: { chartColorScheme: 'default' } }),
    Deferred: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/actions/App/Http/Controllers/AccountController', () => ({
    index: () => ({ url: '/accounts' }),
    show: { url: (id: string) => `/accounts/${id}` },
    updateArchived: { url: (id: string) => `/accounts/${id}/archived` },
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

const { chartComputedData } = vi.hoisted(() => ({
    chartComputedData: { current: null as ChartComputedData | null },
}));

vi.mock('@/components/accounts/account-balance-chart', () => ({
    AccountBalanceChart: ({
        onDataLoaded,
    }: {
        onDataLoaded?: (data: ChartComputedData) => void;
    }) => {
        useEffect(() => {
            if (chartComputedData.current) {
                onDataLoaded?.(chartComputedData.current);
            }
        }, [onDataLoaded]);

        return null;
    },
}));

vi.mock('@/components/accounts/archive-account-dialog', () => ({
    ArchiveAccountDialog: () => null,
}));

const balancesModal = vi.fn();

vi.mock('@/components/accounts/balances-modal', () => ({
    BalancesModal: (props: Record<string, unknown>) => {
        balancesModal(props);
        return null;
    },
}));

vi.mock('@/components/accounts/edit-account-dialog', () => ({
    EditAccountDialog: () => null,
}));

vi.mock('@/components/accounts/edit-loan-detail-dialog', () => ({
    EditLoanDetailDialog: () => null,
}));

const importBalancesDrawer = vi.fn();

vi.mock('@/components/accounts/import-balances-drawer', () => ({
    ImportBalancesDrawer: (props: Record<string, unknown>) => {
        importBalancesDrawer(props);
        return null;
    },
}));

const updateBalanceDialog = vi.fn();

vi.mock('@/components/accounts/update-balance-dialog', () => ({
    UpdateBalanceDialog: (props: Record<string, unknown>) => {
        updateBalanceDialog(props);
        return null;
    },
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
    TransactionListSkeleton: () => null,
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
    bank: null,
    type: 'checking' as const,
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

const connectedAccount = {
    ...baseAccount,
    banking_connection_id: 'connection-1',
};

const investmentAccount = {
    ...baseAccount,
    name: 'Index Fund',
    type: 'investment' as const,
};

const investmentChartData = (
    currentValue: number,
    invested: number,
): ChartComputedData => ({
    chartData: [
        {
            month: 'Jan 2026',
            timestamp: 1,
            value: 100000,
            invested_amount: invested,
        },
        {
            month: 'Feb 2026',
            timestamp: 2,
            value: currentValue,
            invested_amount: invested,
        },
    ],
    currentBalance: currentValue,
    currentInvestedAmount: invested,
    currentMortgageBalance: null,
    currencyCode: 'EUR',
    hasMortgageData: false,
    shortTrend: null,
    longTrend: null,
});

const renderPage = (account = baseAccount) =>
    render(
        <PrivacyModeProvider>
            <AccountShow
                account={account}
                categories={[]}
                accounts={[account]}
                banks={[]}
                labels={[]}
                automationRules={[]}
            />
        </PrivacyModeProvider>,
    );

function openMoreOptionsMenu() {
    fireEvent.pointerDown(screen.getByLabelText('More options'), {
        button: 0,
        ctrlKey: false,
    });

    return screen.findByRole('menu');
}

describe('AccountShow', () => {
    afterEach(() => {
        chartComputedData.current = null;
    });

    /**
     * A manual investment account recorded what went in and what it is worth,
     * and the difference was readable nowhere but a chart tooltip.
     */
    it('reads out the gain of an investment account over what was invested', () => {
        chartComputedData.current = investmentChartData(150000, 120000);

        renderPage(investmentAccount);

        expect(screen.getByText('Invested')).toBeInTheDocument();
        expect(screen.getByText('Gain')).toBeInTheDocument();
        expect(screen.getByText('+25.0% of invested')).toBeInTheDocument();
    });

    it('reads out a loss when the account is worth less than what went in', () => {
        chartComputedData.current = investmentChartData(90000, 120000);

        renderPage(investmentAccount);

        expect(screen.getByText('-25.0% of invested')).toBeInTheDocument();
    });

    it('leaves out the gain cards when no invested amount was ever recorded', () => {
        chartComputedData.current = {
            ...investmentChartData(150000, 120000),
            currentInvestedAmount: null,
        };

        renderPage(investmentAccount);

        expect(screen.queryByText('Invested')).not.toBeInTheDocument();
        expect(screen.queryByText('Gain')).not.toBeInTheDocument();
    });

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

    it('opens create transaction dialog for connected transactional accounts', () => {
        renderPage(connectedAccount);

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

    it('opens the update balance dialog for connected accounts', () => {
        renderPage(connectedAccount);

        fireEvent.click(screen.getByRole('button', { name: 'Update balance' }));

        expect(updateBalanceDialog).toHaveBeenLastCalledWith(
            expect.objectContaining({ open: true, account: connectedAccount }),
        );
    });

    it('opens the import balances drawer for connected accounts', () => {
        renderPage(connectedAccount);

        fireEvent.click(
            screen.getByRole('button', { name: 'Import balances' }),
        );

        expect(importBalancesDrawer).toHaveBeenLastCalledWith(
            expect.objectContaining({ open: true, accountId: 'account-1' }),
        );
    });

    it('opens the balance history for connected accounts', async () => {
        renderPage(connectedAccount);

        const menu = await openMoreOptionsMenu();
        fireEvent.click(within(menu).getByText('See balances'));

        expect(balancesModal).toHaveBeenLastCalledWith(
            expect.objectContaining({ open: true, account: connectedAccount }),
        );
    });

    it('names the balance actions after what the account holds', () => {
        renderPage({ ...connectedAccount, type: 'loan' });

        expect(
            screen.getByRole('button', { name: 'Update owed amount' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Import owed amounts' }),
        ).toBeInTheDocument();
    });

    it('offers both sets of balance actions on a connected property with a loan', () => {
        renderPage({
            ...connectedAccount,
            type: 'real_estate',
            linked_loan_account: { ...baseAccount, id: 'loan-1', type: 'loan' },
        });

        expect(
            screen.getByRole('button', { name: 'Update market value' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Update owed amount' }),
        ).toBeInTheDocument();
    });

    it('hides transaction action for non-transactional accounts', () => {
        renderPage({ ...baseAccount, type: 'real_estate' });

        expect(
            screen.queryByRole('button', { name: 'Add transaction' }),
        ).not.toBeInTheDocument();
    });
});
