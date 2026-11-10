import type { BankConnectionIssue } from '@/types';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BankConnectionIssueBanner } from './bank-connection-issue-banner';

const mocks = vi.hoisted(() => ({
    leavePage: vi.fn(),
    visit: vi.fn(),
    captureEvent: vi.fn(),
    issues: [] as BankConnectionIssue[],
    url: '/dashboard',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: mocks.url,
        props: { bankConnectionIssues: mocks.issues },
    }),
    router: { visit: mocks.visit },
}));

vi.mock('@/lib/leave-page', () => ({ leavePage: mocks.leavePage }));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

vi.mock('@/routes/settings/connections', () => ({
    index: { url: () => '/settings/connections' },
}));

vi.mock('@/utils/i18n', () => ({
    __: (message: string, replacements: Record<string, string> = {}) =>
        Object.entries(replacements).reduce(
            (text, [key, value]) => text.replace(`:${key}`, value),
            message,
        ),
}));

function issue(overrides: Partial<BankConnectionIssue>): BankConnectionIssue {
    return {
        id: 'conn-1',
        aspsp_name: 'Santander',
        provider: 'enablebanking',
        reason: 'expired',
        reconnect_url: '/open-banking/connections/conn-1/reconnect',
        ...overrides,
    };
}

describe('BankConnectionIssueBanner', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        window.localStorage.clear();
        window.sessionStorage.clear();
        mocks.issues = [];
        mocks.url = '/dashboard';
    });

    it('renders nothing while every connection is syncing', () => {
        const { container } = render(<BankConnectionIssueBanner />);

        expect(container).toBeEmptyDOMElement();
        expect(mocks.captureEvent).not.toHaveBeenCalled();
    });

    it('stays off the connections page, which shows each connection state', () => {
        mocks.issues = [issue({})];
        mocks.url = '/settings/connections?tab=banks';

        const { container } = render(<BankConnectionIssueBanner />);

        expect(container).toBeEmptyDOMElement();
    });

    it('names the one bank and sends the user straight to its consent flow', () => {
        mocks.issues = [issue({})];

        render(<BankConnectionIssueBanner />);
        expect(
            screen.getByText('Your Santander connection stopped syncing.'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }));

        expect(mocks.leavePage).toHaveBeenCalledWith(
            '/open-banking/connections/conn-1/reconnect',
        );
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'bank_issue_banner_reconnect_clicked',
            { connections_count: 1, destination: 'bank' },
        );
    });

    it('sends an API-key provider to the connections page, where credentials are updated', () => {
        mocks.issues = [
            issue({
                aspsp_name: 'Kraken',
                provider: 'kraken',
                reason: 'error',
                reconnect_url: null,
            }),
        ];

        render(<BankConnectionIssueBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }));

        expect(mocks.leavePage).not.toHaveBeenCalled();
        expect(mocks.visit).toHaveBeenCalledWith('/settings/connections');
    });

    it('names every bank and points to the connections page when several broke', () => {
        mocks.issues = [
            issue({ id: 'a', aspsp_name: 'BBVA' }),
            issue({ id: 'b', aspsp_name: 'ING', reason: 'error' }),
        ];

        render(<BankConnectionIssueBanner />);
        expect(
            screen.getByText('BBVA and ING stopped syncing.'),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Review connections' }),
        );

        expect(mocks.visit).toHaveBeenCalledWith('/settings/connections');
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'bank_issue_banner_reconnect_clicked',
            { connections_count: 2, destination: 'connections_page' },
        );
    });

    it('reports the view once per session', () => {
        mocks.issues = [issue({})];

        const first = render(<BankConnectionIssueBanner />);
        first.unmount();
        render(<BankConnectionIssueBanner />);

        const views = mocks.captureEvent.mock.calls.filter(
            ([event]) => event === 'bank_issue_banner_viewed',
        );
        expect(views).toEqual([
            [
                'bank_issue_banner_viewed',
                {
                    connections_count: 1,
                    providers: ['enablebanking'],
                    reasons: ['expired'],
                },
            ],
        ]);
    });

    it('stays dismissed for the day, and comes back when another connection breaks', () => {
        mocks.issues = [issue({})];

        const first = render(<BankConnectionIssueBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(first.container).toBeEmptyDOMElement();
        first.unmount();

        const again = render(<BankConnectionIssueBanner />);
        expect(again.container).toBeEmptyDOMElement();
        again.unmount();

        mocks.issues = [issue({}), issue({ id: 'conn-2', aspsp_name: 'ING' })];
        render(<BankConnectionIssueBanner />);
        expect(
            screen.getByText('Santander and ING stopped syncing.'),
        ).toBeInTheDocument();
    });

    it('comes back the next day while the connection is still broken', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date(2026, 10, 10, 12));
        mocks.issues = [issue({})];

        const first = render(<BankConnectionIssueBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        first.unmount();

        vi.setSystemTime(new Date(2026, 10, 11, 9));
        act(() => {
            render(<BankConnectionIssueBanner />);
        });

        expect(
            screen.getByText('Your Santander connection stopped syncing.'),
        ).toBeInTheDocument();
        vi.useRealTimers();
    });
});
