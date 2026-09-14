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
    usePage: () => ({ props: { features: {}, locale: 'es' } }),
}));
vi.mock('@/utils/i18n', () => ({ __: (key: string) => key }));

// Radix Select relies on pointer APIs missing in jsdom; a native select keeps
// the country step driveable without that brittleness.
vi.mock('@/components/ui/select', () => ({
    Select: ({
        children,
        onValueChange,
    }: {
        children: React.ReactNode;
        onValueChange: (value: string) => void;
    }) => (
        <select
            data-testid="country-select"
            onChange={(e) => onValueChange(e.target.value)}
        >
            {children}
        </select>
    ),
    SelectTrigger: () => null,
    SelectValue: () => null,
    SelectContent: ({ children }: { children: React.ReactNode }) => (
        <>{children}</>
    ),
    SelectItem: ({
        children,
        value,
    }: {
        children: React.ReactNode;
        value: string;
    }) => <option value={value}>{children}</option>,
}));

/** Country step through to the bank list, with BBVA available in Spain. */
async function reachBankStep() {
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

    render(<ConnectAccountInline onBack={vi.fn()} />);

    fireEvent.change(screen.getByTestId('country-select'), {
        target: { value: 'ES' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

    await waitFor(() =>
        expect(
            screen.getByPlaceholderText('Search banks...'),
        ).toBeInTheDocument(),
    );
}

async function reachConnect(bank: RegExp) {
    await reachBankStep();

    fireEvent.click(screen.getByRole('button', { name: bank }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

    return screen.getByRole('button', { name: 'Connect' });
}

describe('ConnectAccountInline analytics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    // The redirect out to the bank is the last thing we can see before the user
    // leaves the app, and the widest gap in the onboarding funnel.
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
