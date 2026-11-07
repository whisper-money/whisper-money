import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAccountsHub, type ExistingAccount } from './step-accounts-hub';

vi.mock('@/lib/posthog', () => ({
    captureEvent: vi.fn(),
    registerProperties: vi.fn(),
    unregisterProperty: vi.fn(),
}));

import { pageProps } from '@/lib/onboarding-page-props';

const visit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
    router: { visit, reload: vi.fn() },
}));

vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

vi.mock('@/components/open-banking/connect-broker-inline', () => ({
    ConnectBrokerInline: () => <div data-testid="broker-picker" />,
}));

function renderHub(props: Partial<Parameters<typeof StepAccountsHub>[0]> = {}) {
    render(
        <StepAccountsHub
            banks={[]}
            isFirstAccount={false}
            onAccountCreated={vi.fn()}
            {...props}
        />,
    );
}

const nomina: ExistingAccount = {
    id: 'a1',
    name: 'Nómina',
    type: 'checking',
    currency_code: 'EUR',
    iban_tail: null,
    bank_id: 'bank-1',
    banking_connection_id: null,
    bank: { id: 'bank-1', name: 'BBVA', logo: null },
};

// A latam subscriber pays for a plan without the bank aggregator: banks are
// shown locked behind the standard plan, and the brokers it includes are open.
describe('StepAccountsHub on the latam plan', () => {
    beforeEach(() => {
        visit.mockClear();
        pageProps.openBankingEnabled = true;
        pageProps.auth.hasProPlan = true;
        pageProps.auth.bankAggregatorLocked = true;
    });

    it('shows the bank locked, and sends it to billing instead of the picker', () => {
        renderHub({ isFirstAccount: true });

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
        expect(
            screen.getByText('Available on the standard plan'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByText('Connect a bank'));

        expect(visit).toHaveBeenCalledWith('/settings/billing');
    });

    it('offers the broker its plan includes, straight to the picker', () => {
        renderHub({ isFirstAccount: true });

        fireEvent.click(screen.getByText('Connect a broker'));

        expect(visit).not.toHaveBeenCalled();
        expect(screen.getByTestId('broker-picker')).toBeInTheDocument();
    });

    it('marks another bank as a standard plan feature once accounts are in', () => {
        renderHub({ existingAccounts: [nomina] });

        expect(screen.getByText('Another bank')).toBeInTheDocument();
        expect(
            screen.getByText('Available on the standard plan'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByText('Another bank'));

        expect(visit).toHaveBeenCalledWith('/settings/billing');
    });
});
