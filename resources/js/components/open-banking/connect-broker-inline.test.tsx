import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConnectBrokerInline } from './connect-broker-inline';

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

function mockConnect(response: { ok: boolean; json: () => Promise<unknown> }) {
    global.fetch = vi
        .fn()
        .mockResolvedValue(response) as unknown as typeof fetch;
}

/** The credential form for one provider, reached the way the hub reaches it. */
function openProvider(name: RegExp) {
    render(<ConnectBrokerInline onBack={vi.fn()} onManual={vi.fn()} />);

    fireEvent.click(screen.getByRole('button', { name }));
}

describe('ConnectBrokerInline', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockConnect({
            ok: true,
            json: async () => ({
                redirect_url: '/onboarding?step=create-account',
            }),
        });
    });

    it('lists the providers the country offers, whole', () => {
        render(<ConnectBrokerInline onBack={vi.fn()} />);

        expect(screen.getByText('Which broker or fund?')).toBeInTheDocument();
        expect(screen.getByText('Indexa Capital')).toBeInTheDocument();
        expect(screen.getByText('Interactive Brokers')).toBeInTheDocument();
    });

    // What the account will and will not bring in, said before the user spends
    // a screen on it: a broker reports holdings, so no transactions are coming.
    it('says what each provider hands over, and what it does not', () => {
        render(<ConnectBrokerInline onBack={vi.fn()} />);

        expect(
            screen.getAllByText('API Token · holdings, not movements').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getByText('Personal API Token · balance and movements'),
        ).toBeInTheDocument();
    });

    it('asks for the credentials the registry names', () => {
        openProvider(/Coinbase/);

        expect(screen.getByText('Connect Coinbase')).toBeInTheDocument();
        expect(screen.getByLabelText('App Key ID')).toBeInTheDocument();
        expect(screen.getByLabelText('Secret')).toBeInTheDocument();
        // The one condition Coinbase's own form gets wrong by default.
        expect(
            screen.getByText(/Opt-out of IP allowlisting/),
        ).toBeInTheDocument();
    });

    it('warns that no transactions are coming, except where they are', () => {
        openProvider(/Indexa Capital/);

        expect(screen.getByText('No transactions')).toBeInTheDocument();
    });

    it('holds the connect button until every field is filled in', () => {
        openProvider(/Binance/);

        const connect = screen.getByRole('button', { name: 'Connect' });
        expect(connect).toBeDisabled();

        fireEvent.change(screen.getByLabelText('API Key'), {
            target: { value: 'key' },
        });
        expect(connect).toBeDisabled();

        fireEvent.change(screen.getByLabelText('API Secret'), {
            target: { value: 'secret' },
        });
        expect(connect).toBeEnabled();
    });

    it('posts the credentials to the provider endpoint and leaves the page', async () => {
        openProvider(/Indexa Capital/);

        fireEvent.change(screen.getByLabelText('API Token'), {
            target: { value: 'a-token' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        await waitFor(() =>
            expect(global.fetch).toHaveBeenCalledWith(
                '/open-banking/indexa-capital/connect',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ api_token: 'a-token' }),
                }),
            ),
        );

        await waitFor(() =>
            expect(leavePage).toHaveBeenCalledWith(
                '/onboarding?step=create-account',
            ),
        );
    });

    // `aspsp_country` is only ever a label for a global exchange, but the
    // endpoint requires one, so the guess has to survive to the request body.
    it('sends the guessed country for the providers that ask for one', async () => {
        openProvider(/Coinbase/);

        fireEvent.change(screen.getByLabelText('App Key ID'), {
            target: { value: 'key-id' },
        });
        fireEvent.change(screen.getByLabelText('Secret'), {
            target: { value: 'a-secret' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        await waitFor(() =>
            expect(global.fetch).toHaveBeenCalledWith(
                '/open-banking/coinbase/connect',
                expect.objectContaining({
                    body: JSON.stringify({
                        api_key_name: 'key-id',
                        private_key: 'a-secret',
                        country: 'ES',
                    }),
                }),
            ),
        );
    });

    it('reports the connection start the way the bank path does', async () => {
        openProvider(/Indexa Capital/);

        fireEvent.change(screen.getByLabelText('API Token'), {
            target: { value: 'a-token' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_bank_connect_started',
            { country: 'ES', provider: 'indexacapital' },
        );
    });

    // A rejected key is the whole point of the screen failing well: the user
    // stays on the form with what the provider actually said.
    it('keeps the user on the form when the credentials are refused', async () => {
        mockConnect({
            ok: false,
            json: async () => ({ message: 'Invalid API token.' }),
        });

        openProvider(/Indexa Capital/);

        fireEvent.change(screen.getByLabelText('API Token'), {
            target: { value: 'wrong' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(
            await screen.findByText('Invalid API token.'),
        ).toBeInTheDocument();
        expect(leavePage).not.toHaveBeenCalled();
        expect(screen.getByLabelText('API Token')).toHaveValue('wrong');
    });

    it('goes back to the list, and only then out', () => {
        const onBack = vi.fn();
        render(<ConnectBrokerInline onBack={onBack} />);

        fireEvent.click(screen.getByRole('button', { name: /Indexa Capital/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(screen.getByText('Which broker or fund?')).toBeInTheDocument();
        expect(onBack).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(onBack).toHaveBeenCalled();
    });

    // Arriving with a provider already chosen means the list belongs to the
    // screen behind this one.
    it('goes straight out when it opened on a provider', () => {
        const onBack = vi.fn();
        render(
            <ConnectBrokerInline
                onBack={onBack}
                initialProvider={{
                    providerKey: 'indexacapital',
                    institution: {
                        name: 'Indexa Capital',
                        country: 'ES',
                        logo: null,
                        maximum_consent_validity: null,
                    },
                    endpoint: '/open-banking/indexa-capital/connect',
                    headerDescription: '',
                    cardDescription: '',
                    fields: [
                        {
                            key: 'api_token',
                            label: 'API Token',
                            type: 'password',
                        },
                    ],
                    help: {
                        before: '',
                        href: 'https://example.test',
                        link: 'there',
                    },
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(onBack).toHaveBeenCalled();
    });
});
