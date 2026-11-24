import type { NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import type { DashboardProps } from '@/types/dashboard';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { AccountBalancesWidget } from './account-balances-widget';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en-US', auth: { user: { currency_code: 'EUR' } } },
    }),
    Deferred: ({ children }: { children: ReactNode }) => children,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { patch: vi.fn(), reload: vi.fn() },
}));

vi.mock('@/actions/App/Http/Controllers/AccountController', () => ({
    show: { url: (id: string) => `/accounts/${id}` },
    reorder: { url: () => '/accounts/reorder' },
    updateVisibility: { url: (id: string) => `/accounts/${id}/visibility` },
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
    usePrivacyReveal: () => null,
    PrivacyRevealScope: ({ children }: { children: ReactNode }) => children,
}));

vi.mock('@/components/accounts/update-balance-dialog', () => ({
    UpdateBalanceDialog: () => null,
}));

vi.mock('@/components/dashboard/accounts-manager-dialog', () => ({
    AccountsManagerDialog: () => null,
}));

const bank = { id: 'bank-1', user_id: null, name: 'Bank', logo: null };

function evolution(loanBalance: number | null): NetWorthEvolutionData {
    return {
        currency_code: 'EUR',
        accounts: {
            home: {
                id: 'home',
                name: 'Piso',
                type: 'real_estate',
                currency_code: 'EUR',
                bank,
                banking_connection_id: null,
                linked_loan_account_id: 'loan',
                tracked_since: '2026-01-01',
                balance_since: '2026-01-01',
            },
            loan: {
                id: 'loan',
                name: 'Hipoteca piso',
                type: 'loan',
                currency_code: 'EUR',
                bank,
                banking_connection_id: null,
                tracked_since: loanBalance === null ? null : '2026-01-01',
                balance_since: loanBalance === null ? null : '2026-01-01',
            },
        },
        data: [
            { month: '2026-10', home: 30000000, loan: loanBalance ?? 0 },
            { month: '2026-11', home: 30000000, loan: loanBalance ?? 0 },
        ],
    };
}

function renderWidget(netWorthEvolution: NetWorthEvolutionData) {
    render(
        <AccountBalancesWidget
            data={{ netWorthEvolution } as unknown as DashboardProps}
            size={{ width: '1/1', height: 'a' }}
        />,
    );
}

describe('AccountBalancesWidget', () => {
    it('folds a mortgage with a balance into its property card', () => {
        renderWidget(evolution(20000000));

        expect(screen.getByText(/Mortgage at/)).toBeInTheDocument();
        expect(screen.queryByText('Hipoteca piso')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: '€100,000.00' }),
        ).toBeInTheDocument();
    });

    it('keeps a mortgage with no balance on its own card asking for it, instead of counting it as paid off', () => {
        renderWidget(evolution(null));

        expect(screen.queryByText(/Mortgage at/)).not.toBeInTheDocument();
        expect(screen.getByText('Hipoteca piso')).toBeInTheDocument();
        expect(screen.getByText('No balance yet')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Add what you still owe' }),
        ).toBeInTheDocument();
        // The property shows its market value, not a made-up equity.
        expect(
            screen.getByRole('button', { name: '€300,000.00' }),
        ).toBeInTheDocument();
    });
});
