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
                transactionAnalysis: false,
                manageBankAccounts: true,
            },
        },
    }),
    usePoll: () => ({
        start: mocks.pollStart,
        stop: mocks.pollStop,
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

vi.mock('@/components/open-banking/upgrade-connection-dialog', () => ({
    UpgradeConnectionDialog: () => null,
}));

vi.mock('@/components/open-banking/connection-status-badge', () => ({
    ConnectionStatusBadge: ({ status }: { status: string }) => (
        <span>{status}</span>
    ),
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
