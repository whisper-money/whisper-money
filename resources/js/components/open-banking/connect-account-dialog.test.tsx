import type { BankingConnection } from '@/types/banking';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConnectAccountDialog } from './connect-account-dialog';

globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { features: {}, locale: 'es' } }),
}));

vi.mock('@/utils/i18n', () => ({
    __: (key: string) => key,
}));

vi.mock('@/lib/csrf', () => ({
    getCsrfToken: () => 'test-token',
}));

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

function liveBbvaConnection(): BankingConnection {
    return {
        id: crypto.randomUUID(),
        provider: 'enablebanking',
        aspsp_name: 'BBVA',
        aspsp_country: 'ES',
        status: 'active',
        valid_until: null,
        last_synced_at: null,
        error_message: null,
        accounts_count: 1,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
    };
}

type Institution = {
    name: string;
    country: string;
    logo: string;
    maximum_consent_validity: null;
    beta?: boolean;
};

function institution(name: string, logo = '', beta = false): Institution {
    return { name, country: 'ES', logo, maximum_consent_validity: null, beta };
}

async function reachBankStep(
    connections: BankingConnection[],
    institutions: Institution[] = [
        institution('BBVA'),
        institution('CaixaBank'),
    ],
) {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => institutions,
    }) as unknown as typeof fetch;

    render(
        <ConnectAccountDialog
            open
            onOpenChange={vi.fn()}
            connections={connections}
        />,
    );

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

describe('ConnectAccountDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('names the countries in the active locale', () => {
        render(<ConnectAccountDialog open onOpenChange={vi.fn()} />);

        expect(
            screen.getByRole('option', { name: 'España' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Spain' }),
        ).not.toBeInTheDocument();
    });

    it('shows Interactive Brokers as a connectable provider', async () => {
        await reachBankStep([]);

        expect(
            screen.getByRole('button', { name: /Interactive Brokers/ }),
        ).toBeInTheDocument();
    });

    it('keeps an already-connected bank selectable and badges it', async () => {
        await reachBankStep([liveBbvaConnection()]);

        const bbva = screen.getByRole('button', { name: /BBVA/ });
        expect(bbva).not.toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByText('Already connected')).toBeInTheDocument();

        // A bank without a live connection shows no badge.
        const caixa = screen.getByRole('button', { name: /CaixaBank/ });
        expect(caixa).not.toHaveTextContent('Already connected');
    });

    it('gates Connect behind the replace acknowledgement', async () => {
        await reachBankStep([liveBbvaConnection()]);

        fireEvent.click(screen.getByRole('button', { name: /BBVA/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.getByText('This may replace your existing connection'),
        ).toBeInTheDocument();

        const connect = screen.getByRole('button', { name: 'Connect' });
        expect(connect).toBeDisabled();

        fireEvent.click(screen.getByRole('checkbox'));
        expect(connect).toBeEnabled();
    });

    it('requires every provider credential before connecting', async () => {
        await reachBankStep([]);

        fireEvent.click(screen.getByRole('button', { name: /Binance/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

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

    it('does not warn when connecting a fresh bank', async () => {
        await reachBankStep([liveBbvaConnection()]);

        fireEvent.click(screen.getByRole('button', { name: /CaixaBank/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.queryByText('This may replace your existing connection'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Connect' })).toBeEnabled();
    });

    it('shows Wise once, preferring the native integration over the aggregator', async () => {
        await reachBankStep(
            [],
            [institution('Abanca'), institution('Wise', 'aggregator-logo')],
        );

        expect(screen.getAllByText('Wise')).toHaveLength(1);
        // The surviving entry is the native provider, with its own logo —
        // not the aggregator's.
        const wiseButton = screen.getByRole('button', { name: 'Wise' });
        expect(wiseButton.querySelector('img')).toHaveAttribute(
            'src',
            '/images/banks/logos/wise.png',
        );
    });

    it('does not duplicate banks when the search is filtered repeatedly', async () => {
        await reachBankStep(
            [],
            [institution('Abanca'), institution('Wise', 'aggregator-logo')],
        );
        const search = screen.getByPlaceholderText('Search banks...');

        for (const query of ['wise', '', 'wise', '']) {
            fireEvent.change(search, { target: { value: query } });
        }

        expect(screen.getAllByText('Wise')).toHaveLength(1);
    });

    it('badges the banks the provider still calls beta', async () => {
        await reachBankStep(
            [],
            [institution('Openbank', '', true), institution('BBVA')],
        );

        expect(
            screen.getByRole('button', { name: /Openbank/ }),
        ).toHaveTextContent('Beta');
        expect(
            screen.getByRole('button', { name: /^BBVA/ }),
        ).not.toHaveTextContent('Beta');
    });

    it('spells the beta caveat out on the confirm step', async () => {
        await reachBankStep(
            [],
            [institution('Openbank', '', true), institution('BBVA')],
        );

        fireEvent.click(screen.getByRole('button', { name: /Openbank/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.getByText('This bank is still in beta'),
        ).toBeInTheDocument();
        // Still connectable: beta informs, it does not gate.
        expect(screen.getByRole('button', { name: 'Connect' })).toBeEnabled();
    });

    it('says nothing about beta for a stable connector', async () => {
        await reachBankStep(
            [],
            [institution('Openbank', '', true), institution('BBVA')],
        );

        fireEvent.click(screen.getByRole('button', { name: /^BBVA/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.queryByText('This bank is still in beta'),
        ).not.toBeInTheDocument();
    });
});
