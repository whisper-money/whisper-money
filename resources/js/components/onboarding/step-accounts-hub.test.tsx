import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepAccountsHub, type ExistingAccount } from './step-accounts-hub';

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return { usePage: () => ({ props: pageProps }) };
});

vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

// The price and the plan condition are one line inside the connect row, so a
// single matcher covers both.
const PLAN_NOTICE = /^Standard plan, from\s*€.*choose a plan at the end/;
const PLAN_CONDITION = /choose a plan at the end of the onboarding/;

function existingAccount(
    overrides: Partial<ExistingAccount> & Pick<ExistingAccount, 'id' | 'name'>,
): ExistingAccount {
    return {
        name_iv: null,
        encrypted: false,
        type: 'checking',
        currency_code: 'EUR',
        bank_id: 'bank-1',
        banking_connection_id: null,
        bank: { id: 'bank-1', name: 'BBVA', logo: null },
        ...overrides,
    };
}

function createdAccount(
    overrides: Partial<CreatedAccount> & Pick<CreatedAccount, 'id' | 'name'>,
): CreatedAccount {
    return {
        type: 'checking',
        currencyCode: 'EUR',
        bankName: 'BBVA',
        ...overrides,
    };
}

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

describe('StepAccountsHub', () => {
    it('asks for the first account with both ways in and no list', () => {
        renderHub({ isFirstAccount: true });

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
        expect(screen.getByText('Add one myself')).toBeInTheDocument();
        expect(screen.queryByText('Usually missed')).not.toBeInTheDocument();
    });

    it('counts the accounts in and asks for what open banking never returns', () => {
        renderHub({
            existingAccounts: [
                existingAccount({
                    id: 'a1',
                    name: 'Cuenta Nómina',
                    banking_connection_id: 'connection-1',
                }),
                existingAccount({
                    id: 'a2',
                    name: 'Tarjeta Crédito',
                    type: 'credit_card',
                    banking_connection_id: 'connection-1',
                }),
            ],
        });

        expect(screen.getByText("2 in. What's missing?")).toBeInTheDocument();
        expect(screen.getByText('BBVA · syncing daily')).toBeInTheDocument();
        expect(screen.getByText('A mortgage or a loan')).toBeInTheDocument();
        expect(screen.getByText('A pension or a broker')).toBeInTheDocument();
        expect(screen.getByText('Another bank')).toBeInTheDocument();
    });

    // The step polls for accounts finalized in another browser while the user
    // adds more here, so the same account arrives down both routes. Counting it
    // twice would make the hub claim more than the user has.
    it('lists an account once when it arrives as both created and existing', () => {
        renderHub({
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
            createdAccounts: [createdAccount({ id: 'a1', name: 'Savings' })],
        });

        expect(screen.getByText("1 in. What's missing?")).toBeInTheDocument();
        expect(screen.getAllByText('Savings')).toHaveLength(1);
    });

    // A bank keeps itself current; an account typed in by hand does not, and
    // saying otherwise promises a sync that will never happen.
    it('promises no syncing for a bank whose accounts were all added by hand', () => {
        renderHub({
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
        });

        expect(screen.getByText('BBVA')).toBeInTheDocument();
        expect(
            screen.queryByText('BBVA · syncing daily'),
        ).not.toBeInTheDocument();
    });

    // A free signup is never offered a bank, so the one suggestion that needs
    // a token goes, and the one that reads the same either way stays.
    it('leaves a free signup only the suggestions it can act on', () => {
        renderHub({
            signupPlan: 'free',
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
        });

        expect(screen.getByText('A mortgage or a loan')).toBeInTheDocument();
        expect(screen.getByText('Another bank')).toBeInTheDocument();
        expect(
            screen.queryByText('A pension or a broker'),
        ).not.toBeInTheDocument();
    });

    it('comes back to the hub from the manual form rather than out of the step', () => {
        renderHub({
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
        });

        fireEvent.click(screen.getByText('A mortgage or a loan'));
        expect(screen.getByTestId('account-form')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Back'));
        expect(screen.getByText("1 in. What's missing?")).toBeInTheDocument();
    });

    // The whole point of that row sitting under "usually missed" rather than
    // next to the manual form: a broker is connected, not typed in.
    it('takes the pension row to the brokers, not to the manual form', () => {
        renderHub({
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
        });

        fireEvent.click(screen.getByText('A pension or a broker'));

        expect(screen.getByText('Which broker or fund?')).toBeInTheDocument();
        expect(screen.getByText('Indexa Capital')).toBeInTheDocument();
        expect(screen.queryByTestId('account-form')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Back'));
        expect(screen.getByText("1 in. What's missing?")).toBeInTheDocument();
    });

    it('ends the step only when the user says there is nothing left', () => {
        const onContinue = vi.fn();

        renderHub({
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
            onContinue,
        });

        fireEvent.click(screen.getByText("That's everything — continue"));

        expect(onContinue).toHaveBeenCalledOnce();
    });
});

describe('StepAccountsHub plan intent', () => {
    it('offers no bank to a free signup, whose empty hub is the manual form', () => {
        renderHub({ isFirstAccount: true, signupPlan: 'free' });

        expect(screen.queryByText('Connect a bank')).not.toBeInTheDocument();
        expect(screen.getByTestId('account-form')).toBeInTheDocument();
    });

    it('quotes no price to a paid signup, who has just seen one', () => {
        renderHub({ isFirstAccount: true, signupPlan: 'paid' });

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
        expect(screen.queryByText(PLAN_CONDITION)).not.toBeInTheDocument();
    });

    it('keeps the bank row, the price and the plan warning for every other signup', () => {
        renderHub({ isFirstAccount: true });

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
        expect(screen.getByText(PLAN_NOTICE)).toBeInTheDocument();
    });

    // A resumed signup lands straight on the hub with a list, so it may be the
    // first screen where a bank is offered at all — the disclosure has to be
    // on it, and on one row rather than under all three.
    it('discloses the plan once on a hub that already has accounts', () => {
        renderHub({
            existingAccounts: [existingAccount({ id: 'a1', name: 'Savings' })],
        });

        expect(screen.getAllByText(PLAN_NOTICE)).toHaveLength(1);
    });
});
