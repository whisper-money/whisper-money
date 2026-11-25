import type { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AccountBalanceCard } from './account-balance-card';

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
    usePrivacyReveal: () => null,
}));

vi.mock('@/components/accounts/update-balance-dialog', () => ({
    UpdateBalanceDialog: ({ open }: { open: boolean }) =>
        open ? <div role="dialog">Update balance</div> : null,
}));

function account(
    overrides: Partial<AccountWithMetrics> = {},
): AccountWithMetrics {
    return {
        id: 'acc-1',
        name: 'Fondo indexado',
        type: 'investment',
        currency_code: 'EUR',
        bank: null,
        banking_connection_id: null,
        currentBalance: 800000,
        previousBalance: 800000,
        diff: 0,
        history: [{ date: 'Nov', value: 800000 }],
        hasBalance: true,
        hasPreviousBalance: false,
        balanceSince: '2026-11-24',
        investedAmount: null,
        hidden_on_dashboard: false,
        archived_at: null,
        ...overrides,
    } as unknown as AccountWithMetrics;
}

describe('AccountBalanceCard', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-11-24T12:00:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('says the account was added today instead of comparing with a month it did not exist in', () => {
        render(<AccountBalanceCard account={account()} />);

        expect(
            screen.getByRole('button', { name: '€8,000.00' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Added today')).toBeInTheDocument();
        expect(screen.queryByText('vs last month')).not.toBeInTheDocument();
    });

    it('says when the balance started being known when that was earlier this month', () => {
        render(
            <AccountBalanceCard
                account={account({ balanceSince: '2026-11-03' })}
            />,
        );

        expect(screen.getByText('Added Nov 3')).toBeInTheDocument();
        expect(screen.queryByText('vs last month')).not.toBeInTheDocument();
    });

    it('compares with last month when there is a figure for it', () => {
        render(
            <AccountBalanceCard
                account={account({
                    currentBalance: 320000,
                    previousBalance: 299000,
                    diff: 21000,
                    hasPreviousBalance: true,
                    balanceSince: '2026-06-01',
                })}
            />,
        );

        expect(screen.getByText('vs last month')).toBeInTheDocument();
        expect(screen.getByText('€210.00')).toBeInTheDocument();
        expect(screen.queryByText(/^Added/)).not.toBeInTheDocument();
    });

    it('asks for what is still owed on a loan with no balance, and never prints -0', () => {
        const { container } = render(
            <AccountBalanceCard
                account={account({
                    name: 'Hipoteca piso',
                    type: 'loan',
                    currentBalance: 0,
                    previousBalance: 0,
                    history: [],
                    hasBalance: false,
                    balanceSince: null,
                })}
            />,
        );

        expect(screen.getByText('No balance yet')).toBeInTheDocument();
        expect(container.textContent).not.toMatch(/-\s*€\s*0/);
        expect(screen.queryByText('vs last month')).not.toBeInTheDocument();
        expect(
            container.querySelector('[data-slot="card"]')?.className,
        ).toContain('border-dashed');

        fireEvent.click(
            screen.getByRole('button', { name: 'Add what you still owe' }),
        );

        expect(screen.getByRole('dialog')).toHaveTextContent('Update balance');
    });

    it('sends a connected account with no balance to its page to set one', () => {
        render(
            <AccountBalanceCard
                account={account({
                    banking_connection_id: 'conn-1',
                    hasBalance: false,
                    balanceSince: null,
                    currentBalance: 0,
                    history: [],
                })}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Add balance' }),
        ).toHaveAttribute('href', '/accounts/acc-1');
    });

    it('does not compare equity when the linked mortgage has no figure for last month', () => {
        render(
            <AccountBalanceCard
                account={account({
                    type: 'real_estate',
                    currentBalance: 30000000,
                    previousBalance: 30000000,
                    hasPreviousBalance: true,
                    balanceSince: '2026-01-01',
                })}
                linkedLoanMetrics={{
                    currentBalance: 20000000,
                    previousBalance: 20000000,
                    hasPreviousBalance: false,
                    diff: 0,
                    history: [],
                }}
            />,
        );

        expect(
            screen.getByRole('button', { name: '€100,000.00' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('vs last month')).not.toBeInTheDocument();
    });
});
