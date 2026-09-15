import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConnectAccountInline } from './connect-account-inline';

const { captureEvent, leavePage } = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    leavePage: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@/lib/leave-page', () => ({ leavePage }));
vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'test-token' }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { features: {}, locale: 'es-ES' } }),
}));
vi.mock('@/utils/i18n', () => ({
    __: (key: string, replacements?: Record<string, string | number>) =>
        Object.entries(replacements ?? {}).reduce(
            (text, [name, value]) => text.replace(`:${name}`, String(value)),
            key,
        ),
}));

function mockInstitutions() {
    global.fetch = vi.fn().mockImplementation((url: string) =>
        Promise.resolve({
            ok: true,
            json: async () =>
                String(url).includes('institutions')
                    ? [
                          {
                              name: 'BBVA',
                              country: 'ES',
                              logo: '',
                              maximum_consent_validity: null,
                          },
                      ]
                    : { redirect_url: 'https://bank.test/authorize' },
        }),
    ) as unknown as typeof fetch;
}

/** The bank list, which is where the flow now opens. */
async function reachBankStep() {
    mockInstitutions();

    render(<ConnectAccountInline onBack={vi.fn()} onManual={vi.fn()} />);

    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: /BBVA/ }),
        ).toBeInTheDocument(),
    );
}

async function reachConnect(bank: RegExp) {
    await reachBankStep();

    fireEvent.click(screen.getByRole('button', { name: bank }));

    return screen.getByRole('button', { name: /^Continue to/ });
}

describe('ConnectAccountInline', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    // The country is part of a bank's identity, not a filter, so the flow may
    // never reach the provider without one. Guessing it from the user's own
    // region is what turns a whole step into a control beside the search box.
    it('opens on the banks of the country guessed from the locale', async () => {
        mockInstitutions();

        render(<ConnectAccountInline onBack={vi.fn()} />);

        await waitFor(() =>
            expect(global.fetch).toHaveBeenCalledWith(
                '/open-banking/institutions?country=ES',
                expect.anything(),
            ),
        );

        // Counts the country's banks alone: the API-key connectors are no
        // longer rows in this list, they are the section under it.
        expect(
            screen.getByPlaceholderText('Search 1 banks'),
        ).toBeInTheDocument();
        expect(screen.getByText('Where do you bank?')).toBeInTheDocument();
    });

    it('offers the full country list behind the country control', async () => {
        await reachBankStep();

        fireEvent.click(screen.getByRole('button', { name: /España/ }));

        expect(
            screen.getByText('Which country is the account in?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Guessed from your settings'),
        ).toBeInTheDocument();
    });

    it('fetches the banks of a country picked by hand', async () => {
        await reachBankStep();

        fireEvent.click(screen.getByRole('button', { name: /España/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Alemania' }));

        await waitFor(() =>
            expect(global.fetch).toHaveBeenCalledWith(
                '/open-banking/institutions?country=DE',
                expect.anything(),
            ),
        );
    });

    // Picking a bank goes straight to the handoff: the old flow made the user
    // select a row and then press Continue to act on the same decision.
    it('takes a chosen bank straight to the handoff', async () => {
        await reachBankStep();

        fireEvent.click(screen.getByRole('button', { name: /BBVA/ }));

        expect(
            screen.getByText('You’re about to log in at BBVA'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Continue to BBVA' }),
        ).toBeInTheDocument();
    });

    // Brokers speak API keys, not open banking: they get a section of their
    // own under the banks, and a screen that promises different things.
    it('offers the brokers under the banks, folded after the first two', async () => {
        await reachBankStep();

        expect(screen.getByText('Brokers and exchanges')).toBeInTheDocument();
        expect(screen.getByText('Indexa Capital')).toBeInTheDocument();
        expect(screen.getByText('Coinbase')).toBeInTheDocument();
        expect(screen.queryByText('Binance')).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /Binance, Bitpanda/ }),
        );

        expect(screen.getByText('Binance')).toBeInTheDocument();
    });

    // They used to be rows in the bank list, so a user who types "Binance" has
    // to keep finding it.
    it('keeps a folded broker reachable from the search box', async () => {
        await reachBankStep();

        fireEvent.change(screen.getByPlaceholderText('Search 1 banks'), {
            target: { value: 'binance' },
        });

        expect(screen.getByText('Binance')).toBeInTheDocument();
        expect(screen.queryByText('Coinbase')).not.toBeInTheDocument();
    });

    it('opens the broker form rather than the bank handoff', async () => {
        await reachBankStep();

        fireEvent.click(screen.getByRole('button', { name: /Indexa Capital/ }));

        expect(screen.getByText('Connect Indexa Capital')).toBeInTheDocument();
        expect(screen.getByLabelText('API Token')).toBeInTheDocument();
    });

    describe('analytics', () => {
        // The redirect out to the bank is the last thing we can see before the
        // user leaves the app, and the widest gap in the onboarding funnel.
        it('reports the country and provider when the bank redirect starts', async () => {
            fireEvent.click(await reachConnect(/BBVA/));

            expect(captureEvent).toHaveBeenCalledOnce();
            expect(captureEvent).toHaveBeenCalledWith(
                'onboarding_bank_connect_started',
                { country: 'ES', provider: 'enable_banking' },
            );
            await waitFor(() => expect(leavePage).toHaveBeenCalled());
        });

        it('reports nothing while the user is still choosing', async () => {
            await reachConnect(/BBVA/);

            expect(captureEvent).not.toHaveBeenCalled();
        });
    });
});
