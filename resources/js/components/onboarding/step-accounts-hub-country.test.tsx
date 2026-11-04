import { rememberConnectCountry } from '@/hooks/use-connect-flow';
import { pageProps } from '@/lib/onboarding-page-props';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAccountsHub, type ExistingAccount } from './step-accounts-hub';

const { captureEvent } = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

const EXISTING_ACCOUNT: ExistingAccount = {
    id: 'a1',
    name: 'Ahorros',
    type: 'savings',
    currency_code: 'COP',
    iban_tail: null,
    bank_id: 'bank-1',
    banking_connection_id: null,
    bank: { id: 'bank-1', name: 'Bancolombia', logo: null },
};

const ESCAPE_HATCH = 'Is your bank in Europe or the UK? Connect it';

function renderHub(props: Partial<Parameters<typeof StepAccountsHub>[0]> = {}) {
    render(
        <StepAccountsHub
            banks={[]}
            isFirstAccount
            onAccountCreated={vi.fn()}
            {...props}
        />,
    );
}

/**
 * Most views of the bank gate came from countries where no bank of ours
 * connects: they were sold a plan for something they could not use. There the
 * hub leads with the manual form and the import, and keeps the bank one quiet
 * tap away for whoever banks back home.
 */
describe('StepAccountsHub in a country with no connectable banks', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
        pageProps.auth.hasProPlan = false;
        pageProps.openBankingEnabled = true;
    });

    it('offers no bank connection and no plan price', () => {
        renderHub({ timezoneCountry: 'CO' });

        expect(screen.queryByText('Connect a bank')).not.toBeInTheDocument();
        expect(screen.getByText('Add one myself')).toBeInTheDocument();
        expect(
            screen.queryByText(/Standard plan, from/),
        ).not.toBeInTheDocument();
        // The callout is about banks the user cannot connect.
        expect(
            screen.queryByText(/Open banking almost never/),
        ).not.toBeInTheDocument();
    });

    it('reports that the bank option was hidden, and why', () => {
        renderHub({ timezoneCountry: 'CO' });

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_bank_option_hidden',
            { reason: 'no_banks_in_country', country: 'CO' },
        );
    });

    it('tags the route out of the hub as taken with the bank hidden', () => {
        renderHub({ timezoneCountry: 'CO' });

        fireEvent.click(screen.getByText('Add one myself'));

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_accounts_hub_route',
            { option: 'manual', accounts: 0, bank_hidden: true },
        );
        expect(screen.getByTestId('account-form')).toBeInTheDocument();
    });

    // A guess from the timezone is never a wall: someone abroad whose bank is
    // at home still reaches the picker — through the same gate as anyone.
    it('still lets a user who insists reach the bank gate', () => {
        renderHub({ timezoneCountry: 'CO' });

        fireEvent.click(screen.getByText(ESCAPE_HATCH));

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_accounts_hub_route',
            { option: 'connect-elsewhere', accounts: 0, bank_hidden: true },
        );
        expect(
            screen.getByText('Connecting a bank needs Standard'),
        ).toBeInTheDocument();
    });

    it('turns the "Another bank" suggestion into the manual form', () => {
        pageProps.auth.hasProPlan = true;

        renderHub({
            timezoneCountry: 'US',
            isFirstAccount: false,
            existingAccounts: [EXISTING_ACCOUNT],
        });

        fireEvent.click(screen.getByText('Another bank, by hand'));

        expect(screen.getByTestId('account-form')).toBeInTheDocument();
    });

    it('keeps the bank for a country picked by hand before', () => {
        rememberConnectCountry('ES');

        renderHub({ timezoneCountry: 'MX' });

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
        expect(screen.queryByText(ESCAPE_HATCH)).not.toBeInTheDocument();
        expect(captureEvent).not.toHaveBeenCalled();
    });

    it.each([
        ['a connectable country', 'ES'],
        ['an unknown country', null],
    ])('keeps the bank for %s', (_, timezoneCountry) => {
        renderHub({ timezoneCountry });

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
        expect(screen.queryByText(ESCAPE_HATCH)).not.toBeInTheDocument();
        expect(captureEvent).not.toHaveBeenCalled();
    });
});
