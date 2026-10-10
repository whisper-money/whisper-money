import type { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { withCreditCardUsage } from '@/hooks/use-dashboard-data';
import type { CreditCardUsage } from '@/types/account';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { AccountBalanceCard } from '../dashboard/account-balance-card';
import { AccountListCard } from './account-list-card';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en-US', auth: { user: { currency_code: 'EUR' } } },
    }),
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('@/actions/App/Http/Controllers/AccountController', () => ({
    show: { url: (id: string) => `/accounts/${id}` },
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('@/components/accounts/update-balance-dialog', () => ({
    UpdateBalanceDialog: () => null,
}));

const usage: CreditCardUsage = {
    limit: 300000,
    used: 124000,
    available: 176000,
    period_from: '2026-04-01',
    period_to: '2026-04-30',
    daily: [
        { date: '2026-04-01', used: 0 },
        { date: '2026-04-02', used: 124000 },
    ],
};

function account(
    overrides: Partial<AccountWithMetrics> = {},
): AccountWithMetrics {
    return {
        id: 'acc-1',
        name: 'Visa',
        type: 'credit_card',
        currency_code: 'EUR',
        bank: null,
        banking_connection_id: null,
        currentBalance: -50000,
        previousBalance: -40000,
        diff: -10000,
        history: [],
        investedAmount: null,
        hidden_on_dashboard: false,
        archived_at: null,
        ...overrides,
    } as unknown as AccountWithMetrics;
}

// The accounts list and the dashboard draw a credit card the same way.
describe.each([
    ['AccountListCard', AccountListCard],
    ['AccountBalanceCard', AccountBalanceCard],
])('%s', (_name, Card) => {
    it('shows what is in use of a credit card against its limit instead of a balance', () => {
        render(
            <Card
                account={withCreditCardUsage(account(), { 'acc-1': usage })}
                displayCurrencyCode="EUR"
            />,
        );

        expect(screen.getByText('€1,240.00')).toBeInTheDocument();
        expect(screen.getByText('€3,000.00')).toBeInTheDocument();
        expect(
            screen.getByRole('progressbar', { name: 'Credit limit in use' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('-€500.00')).not.toBeInTheDocument();
        expect(screen.queryByText('vs last month')).not.toBeInTheDocument();
        // Nothing offers to update a balance the card does not keep.
        expect(
            screen.queryByRole('button', { name: /update balance|€/i }),
        ).not.toBeInTheDocument();
    });

    it('shows only what is in use when the card has no limit', () => {
        render(
            <Card
                account={withCreditCardUsage(account(), {
                    'acc-1': { ...usage, limit: null, available: null },
                })}
                displayCurrencyCode="EUR"
            />,
        );

        expect(screen.getByText('€1,240.00')).toBeInTheDocument();
        expect(screen.getByText('In use')).toBeInTheDocument();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    it('keeps the balance of a credit card while no usage is sent', () => {
        render(
            <Card
                account={withCreditCardUsage(account(), undefined)}
                displayCurrencyCode="EUR"
            />,
        );

        expect(
            screen.getByRole('button', { name: '-€500.00' }),
        ).toBeInTheDocument();
    });
});
