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
    usePoll: () => ({
        start: mocks.pollStart,
        stop: mocks.pollStop,
    }),
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuTrigger: () => null,
    DropdownMenuContent: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuItem: ({
        children,
        onClick,
    }: {
        children: React.ReactNode;
        onClick?: () => void;
    }) => (
        <div role="menuitem" onClick={onClick}>
            {children}
        </div>
    ),
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

    it('polls and spins while a first sync is genuinely running', () => {
        render(
            <ConnectionsPage
                connections={[makeConnection({ last_synced_at: null })]}
            />,
        );

        expect(
            screen.getByText(/syncing transactions and balances/i),
        ).toBeInTheDocument();
        expect(mocks.pollStart).toHaveBeenCalled();
    });

    it('shows a waiting state instead of an endless spinner once the bank refused us', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        last_synced_at: null,
                        error_message: 'Rate limit exceeded.',
                        rate_limited_until: '2999-01-01T12:00:00.000000Z',
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/waiting for the bank/i)).toBeInTheDocument();
        expect(
            screen.getByText(/your bank limits how often/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/next attempt/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/syncing transactions and balances/i),
        ).not.toBeInTheDocument();
        expect(mocks.pollStart).not.toHaveBeenCalled();
    });

    it('drops the next attempt line when the backoff window has already passed', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        last_synced_at: null,
                        error_message: 'Rate limit exceeded.',
                        rate_limited_until: '2020-01-01T12:00:00.000000Z',
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/waiting for the bank/i)).toBeInTheDocument();
        expect(screen.queryByText(/next attempt/i)).not.toBeInTheDocument();
    });
});
