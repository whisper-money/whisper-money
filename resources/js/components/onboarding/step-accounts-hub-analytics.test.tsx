import { type AccountFormData } from '@/components/accounts/account-form';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAccountsHub } from './step-accounts-hub';

const { captureEvent } = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'test-token' }));

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return { usePage: () => ({ props: pageProps }) };
});

// The real form fetches currencies and banks, none of which the event depends
// on: it only needs the filled-in values the step submits.
vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: ({
        onChange,
    }: {
        onChange: (data: AccountFormData) => void;
    }) => {
        onChange({
            displayName: 'Savings',
            bankId: null,
            type: 'savings',
            currencyCode: 'EUR',
            customBank: null,
            balance: null,
            realEstate: null,
            loan: null,
        });

        return <div data-testid="account-form" />;
    },
}));

async function submitManualAccount() {
    const { container } = render(
        <StepAccountsHub
            banks={[]}
            isFirstAccount
            signupPlan="free"
            onAccountCreated={vi.fn()}
        />,
    );

    await act(async () => {
        fireEvent.submit(container.querySelector('#onboarding-account')!);
    });
}

const EXISTING_ACCOUNT = {
    id: 'account-1',
    name: 'Cuenta Nómina',
    type: 'checking' as const,
    currency_code: 'EUR',
    iban_tail: null,
    bank_id: 'bank-1',
    banking_connection_id: 'connection-1',
    bank: { id: 'bank-1', name: 'BBVA', logo: null },
};

describe('StepAccountsHub analytics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('reports the account type once the account exists', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ id: 'account-1' }),
        }) as unknown as typeof fetch;

        await submitManualAccount();

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_account_created',
            { account_type: 'savings' },
        );
    });

    // An account the server rejected is not an account: counting it would show
    // more accounts created than exist.
    it('reports nothing when the account could not be created', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            json: async () => ({ message: 'Nope' }),
        }) as unknown as typeof fetch;

        await submitManualAccount();

        expect(screen.getByText('Nope')).toBeInTheDocument();
        expect(captureEvent).not.toHaveBeenCalled();
    });

    // Which way out of the hub was taken is the one thing the step event cannot
    // say, and the whole question this screen asks.
    it('reports the route out of an empty hub', () => {
        render(
            <StepAccountsHub
                banks={[]}
                isFirstAccount
                onAccountCreated={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByText('Add one myself'));

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_accounts_hub_route',
            { option: 'manual', accounts: 0 },
        );
    });

    // The suggestions are what the hub is for, so a tap on one has to be
    // distinguishable from adding an account the user already had in mind.
    it('reports which missing account a suggestion was taken up on', () => {
        render(
            <StepAccountsHub
                banks={[]}
                isFirstAccount={false}
                existingAccounts={[EXISTING_ACCOUNT]}
                onAccountCreated={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByText('A mortgage or a loan'));

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_accounts_hub_route',
            { option: 'mortgage', accounts: 1 },
        );
    });
});
