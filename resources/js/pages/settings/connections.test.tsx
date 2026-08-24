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
                isSharedAccount: false,
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
                        next_sync_attempt_at: '2999-01-01T12:00:00.000000Z',
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

    it('drops the next attempt line when the server could not name one', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        last_synced_at: null,
                        error_message: 'Rate limit exceeded.',
                        next_sync_attempt_at: null,
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/waiting for the bank/i)).toBeInTheDocument();
        expect(screen.queryByText(/next attempt/i)).not.toBeInTheDocument();
    });

    it('hides the manual sync while the bank has told us to back off', () => {
        render(
            <ConnectionsPage
                connections={[makeConnection({ can_sync_manually: false })]}
            />,
        );

        expect(
            screen.queryByRole('menuitem', { name: /sync now/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: /disconnect/i }),
        ).toBeInTheDocument();
    });

    it('keeps Retry on an errored connection even while a backoff is set', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        status: 'error',
                        error_message: 'Something went wrong.',
                        can_sync_manually: false,
                    }),
                ]}
            />,
        );

        expect(
            screen.getByRole('menuitem', { name: /retry/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /retry/i }),
        ).toBeInTheDocument();
    });

    it('keeps the manual sync when no backoff is in the way', () => {
        render(
            <ConnectionsPage
                connections={[makeConnection({ can_sync_manually: true })]}
            />,
        );

        expect(
            screen.getByRole('menuitem', { name: /sync now/i }),
        ).toBeInTheDocument();
    });

    it('badges a connection whose bank is a beta connector', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({ id: 'c1', aspsp_beta: true }),
                    makeConnection({ id: 'c2', aspsp_beta: false }),
                    makeConnection({ id: 'c3' }),
                ]}
            />,
        );

        // Only the beta one, and neither the stable nor the not-yet-backfilled
        // connection.
        expect(screen.getAllByText('Beta')).toHaveLength(1);
    });

    it('explains a failing beta connector where the user reads the error', () => {
        render(
            <ConnectionsPage
                connections={[
                    makeConnection({
                        status: 'error',
                        error_message: 'Something went wrong.',
                        aspsp_beta: true,
                    }),
                ]}
            />,
        );

        expect(
            screen.getByText(/still in beta at our banking provider/i),
        ).toBeInTheDocument();
    });

    it('keeps the beta explanation out of a healthy connection', () => {
        render(
            <ConnectionsPage
                connections={[makeConnection({ aspsp_beta: true })]}
            />,
        );

        expect(
            screen.queryByText(/still in beta at our banking provider/i),
        ).not.toBeInTheDocument();
    });
});
