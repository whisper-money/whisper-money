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

    return screen.getByRole('button', { name: /^Continue to|^Connect$/ });
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

        // Counts what the list actually offers: the country's banks plus the
        // natively integrated connectors that live in the same picker.
        expect(
            screen.getByPlaceholderText('Search 7 banks'),
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

        it('names the native provider when one handles the bank', async () => {
            const connect = await reachConnect(/Binance/);

            fireEvent.change(screen.getByLabelText('API Key'), {
                target: { value: 'key' },
            });
            fireEvent.change(screen.getByLabelText('API Secret'), {
                target: { value: 'secret' },
            });
            fireEvent.click(connect);

            expect(captureEvent).toHaveBeenCalledWith(
                'onboarding_bank_connect_started',
                { country: 'ES', provider: 'binance' },
            );
        });

        it('reports nothing while the user is still choosing', async () => {
            await reachConnect(/BBVA/);

            expect(captureEvent).not.toHaveBeenCalled();
        });
    });
});
