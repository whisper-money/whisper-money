import type { BankingConnection } from '@/types/banking';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ConnectionsPage from './connections';

const mocks = vi.hoisted(() => ({
    routerPost: vi.fn(),
    routerVisit: vi.fn(),
    pollStart: vi.fn(),
    pollStop: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        post: mocks.routerPost,
        visit: mocks.routerVisit,
    },
    usePage: () => ({
        props: {
            auth: {
                isDemoAccount: false,
                hasProPlan: true,
            },
            flash: {},
            subscriptionsEnabled: false,
            features: {
                cashflow: true,
                calculateBalancesOnImport: false,
            },
        },
    }),
    // Keyed by interval: the page runs two polls, a 5s one while a first sync is
    // actually in flight and a 60s one for a connection parked on the bank.
    usePoll: (interval: number) => ({
        start: () => mocks.pollStart(interval),
        stop: () => mocks.pollStop(interval),
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/open-banking/connect-account-dialog', () => ({
    ConnectAccountDialog: () => null,
}));

vi.mock('@/components/open-banking/disconnect-dialog', () => ({
    DisconnectDialog: () => null,
}));

vi.mock('@/components/open-banking/update-credentials-dialog', () => ({
    UpdateCredentialsDialog: () => null,
}));

vi.mock('@/components/subscription/upgrade-dialog', () => ({
    UpgradeDialog: () => null,
}));

function makeConnection(
    overrides: Partial<BankingConnection> = {},
): BankingConnection {
    return {
        id: 'connection-1',
        provider: 'enablebanking',
        aspsp_name: 'Test Bank',
        aspsp_country: 'ES',
        status: 'active',
        valid_until: null,
        last_synced_at: '2026-01-01T00:00:00.000000Z',
        error_message: null,
        rate_limited_until: null,
        accounts_count: 1,
        created_at: '2026-01-01T00:00:00.000000Z',
        updated_at: '2026-01-01T00:00:00.000000Z',
        ...overrides,
    };
}

describe('ConnectionsPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows visible reconnect buttons for expired EnableBanking connections', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        id: 'connection-1',
                        aspsp_name: 'First Bank',
                        status: 'expired',
                    }),
                    makeConnection({
                        id: 'connection-2',
                        aspsp_name: 'Second Bank',
                        status: 'expired',
                    }),
                ]}
            />,
        );

        expect(
            screen.getAllByRole('button', { name: /reconnect/i }),
        ).toHaveLength(2);
    });
});

describe('a first sync the bank has refused', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    const refused = () =>
        makeConnection({
            last_synced_at: null,
            error_message: 'Rate limit exceeded.',
            rate_limited_until: '2099-01-01T00:00:00.000000Z',
        });

    it('says what happened instead of spinning', () => {
        render(<ConnectionsPage connections={[refused()]} />);

        expect(
            screen.getByText(/The bank limited how often we can ask/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Syncing transactions and balances…'),
        ).not.toBeInTheDocument();
        // The badge is the most prominent thing on the card, so it must not
        // still be claiming the connection is working.
        expect(screen.queryByText('Syncing')).not.toBeInTheDocument();
        expect(screen.getByText('Waiting to sync')).toBeInTheDocument();
    });

    it('drops to the slow poll instead of asking every five seconds', () => {
        render(<ConnectionsPage connections={[refused()]} />);

        expect(mocks.pollStart).not.toHaveBeenCalledWith(5000);
        // Still polled, slowly: the next scheduled sync can fix it while the
        // page is open, and before this the only thing that noticed was the
        // five-second hammering.
        expect(mocks.pollStart).toHaveBeenCalledWith(60_000);
    });

    it('still spins and polls while a first sync is genuinely running', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        last_synced_at: null,
                        error_message: null,
                    }),
                ]}
            />,
        );

        expect(
            screen.getByText('Syncing transactions and balances…'),
        ).toBeInTheDocument();
        expect(mocks.pollStart).toHaveBeenCalledWith(5000);
    });
});
