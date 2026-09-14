import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepAccountsHub } from './step-accounts-hub';

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return {
        usePage: () => ({
            props: { ...pageProps, flash: { error: 'Cancelled by user' } },
        }),
        router: { post: vi.fn() },
    };
});

vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

// The bank flow fetches its institutions on mount; the returns under test never
// get that far, so a stub keeps the screens they do reach identifiable.
vi.mock('@/components/open-banking/connect-account-inline', () => ({
    ConnectAccountInline: ({
        retryBank,
    }: {
        retryBank?: { name: string } | null;
    }) => <div data-testid="connect-flow">{retryBank?.name ?? 'no-retry'}</div>,
}));

function renderHub(
    search: string,
    props: Partial<Parameters<typeof StepAccountsHub>[0]> = {},
) {
    window.history.replaceState({}, '', `/onboarding${search}`);

    render(
        <StepAccountsHub
            banks={[]}
            isFirstAccount={false}
            onAccountCreated={vi.fn()}
            {...props}
        />,
    );
}

afterEach(() => {
    window.history.replaceState({}, '', '/onboarding');
});

describe('StepAccountsHub returns from the bank', () => {
    // A failed authorization used to land on a hub that looked exactly as it did
    // before the user left, with only a toast to explain it.
    it('opens on the failure screen, naming the bank that refused', () => {
        renderHub('?step=create-account&connect_error=BBVA&connect_country=ES');

        expect(screen.getByText('BBVA didn’t let us in')).toBeInTheDocument();
        // The provider's own words, when it gave any.
        expect(screen.getByText('Cancelled by user')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Try BBVA again' }),
        ).toBeInTheDocument();
    });

    it('carries the failed bank into the connect flow as a one-tap retry', () => {
        renderHub('?step=create-account&connect_error=BBVA&connect_country=ES');

        fireEvent.click(screen.getByRole('button', { name: 'Try BBVA again' }));

        expect(screen.getByTestId('connect-flow')).toHaveTextContent('BBVA');
        // Dropped off the URL, so a reload does not replay a dealt-with failure.
        expect(window.location.search).not.toContain('connect_error');
    });

    it('sends "bring a file instead" to the manual form', () => {
        renderHub('?step=create-account&connect_error=BBVA&connect_country=ES');

        fireEvent.click(
            screen.getByRole('button', { name: 'Bring a file instead' }),
        );

        expect(screen.getByTestId('account-form')).toBeInTheDocument();
        expect(window.location.search).not.toContain('connect_error');
    });

    // Without the country there is no retry to offer, and the country is half of
    // what identifies a bank to the provider.
    it('ignores a failure that does not name both the bank and its country', () => {
        renderHub('?step=create-account&connect_error=BBVA');

        expect(
            screen.queryByText('BBVA didn’t let us in'),
        ).not.toBeInTheDocument();
    });

    // A connection still waiting on an answer outranks everything else on the
    // hub: the user has already paid SCA for it.
    it('asks which accounts to keep when a bank left some waiting', () => {
        renderHub('?step=create-account', {
            pendingMapping: {
                connection_id: 'connection-1',
                bank_name: 'BBVA',
                bank_logo: null,
                accounts: [
                    {
                        uid: 'ext-1',
                        name: 'Cuenta Nómina',
                        iban: null,
                        currency: 'EUR',
                    },
                    {
                        uid: 'ext-2',
                        name: 'Cuenta Comunidad',
                        iban: null,
                        currency: 'EUR',
                    },
                ],
            },
        });

        expect(screen.getByText('BBVA gave us 2 accounts')).toBeInTheDocument();
        expect(screen.getByText('Cuenta Nómina')).toBeInTheDocument();
    });
});
