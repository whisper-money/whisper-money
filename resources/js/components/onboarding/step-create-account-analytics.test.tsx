import { type AccountFormData } from '@/components/accounts/account-form';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepCreateAccount } from './step-create-account';

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
        <StepCreateAccount
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

describe('StepCreateAccount analytics', () => {
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
});
