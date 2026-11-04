import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { act, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Paywall from './paywall';

const emptyStats = {
    accountsCount: 3,
    transactionsCount: 1284,
    categoriesCount: 12,
    rulesCount: 5,
    connectionsCount: 3,
    endedAt: '2025-08-12T00:00:00+00:00',
};

const mocks = vi.hoisted(() => ({
    visit: vi.fn(),
    post: vi.fn(),
    httpPost: vi.fn(),
    captureEvent: vi.fn(),
    props: {
        canUseFreePlan: false,
        canEscapeToFreePlan: false,
        canManageConnectionsForFreePlan: false,
        stats: {
            accountsCount: 3,
            transactionsCount: 1284,
            categoriesCount: 12,
            rulesCount: 5,
            connectionsCount: 3,
            endedAt: '2025-08-12T00:00:00+00:00',
        },
    },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

// The paywall shell renders the app logo, which reads the privacy-mode
// context the real app provides app-wide from app.tsx.
vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
    }),
}));

vi.mock('@inertiajs/react', async () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
    }: {
        href: string | { url: string };
        children: React.ReactNode;
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    router: { visit: mocks.visit, post: mocks.post, flushAll: vi.fn() },
    useHttp: (await import('@/lib/use-http.fixture')).fakeUseHttp(
        mocks.httpPost,
    ),
    usePage: () => ({
        props: {
            auth: { user: { id: '1', name: 'Ada', email: 'ada@example.test' } },
            locale: 'en',
            pricing,
            ...mocks.props,
        },
    }),
}));

describe('Paywall', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.httpPost.mockResolvedValue(undefined);
        mocks.props.canUseFreePlan = false;
        mocks.props.canEscapeToFreePlan = false;
        mocks.props.canManageConnectionsForFreePlan = false;
        mocks.props.stats = { ...emptyStats };
    });

    it('always offers a way out to billing, account deletion and log out', () => {
        render(<Paywall />);

        expect(
            screen.getByRole('link', { name: 'Manage billing' }),
        ).toHaveAttribute('href', '/settings/billing');
        expect(
            screen.getByRole('link', { name: 'Delete account' }),
        ).toHaveAttribute('href', '/settings/delete-account');
        expect(screen.getByRole('link', { name: 'Log out' })).toHaveAttribute(
            'href',
            '/logout',
        );
    });

    it('starts on the configured default plan and carries it into checkout', () => {
        render(<Paywall />);

        expect(screen.getByTestId('start-plan')).toHaveAttribute(
            'href',
            expect.stringContaining('plan=yearly'),
        );
    });

    it('carries the plan the user picks into checkout', () => {
        render(<Paywall />);

        fireEvent.click(screen.getByText('Monthly'));

        expect(screen.getByTestId('start-plan')).toHaveAttribute(
            'href',
            expect.stringContaining('plan=monthly'),
        );
    });

    it('renders the price the server hands over', () => {
        render(<Paywall />);

        // The page states the monthly equivalent and the real yearly charge
        // rather than a price of its own.
        expect(
            screen.getByText('€4.50/month, billed as €53.94 a year'),
        ).toBeInTheDocument();
        expect(screen.getByText('€8.99 a month')).toBeInTheDocument();
    });

    // The amount is on the button, so it is readable with the plan rows
    // scrolled off or hidden behind the sticky footer.
    it('names the amount on the button that charges it', () => {
        render(<Paywall />);

        expect(screen.getByTestId('start-plan')).toHaveTextContent(
            'Start Standard — €53.94 today',
        );

        fireEvent.click(screen.getByText('Monthly'));

        expect(screen.getByTestId('start-plan')).toHaveTextContent(
            'Start Standard — €8.99 today',
        );
    });

    /**
     * The soft gate is the only one a signup can reach now: a bank and the AI
     * both need a plan before they can be switched on, so nobody arrives here
     * with something to be cut off from.
     */
    describe('soft gate', () => {
        beforeEach(() => {
            mocks.props.canUseFreePlan = true;
        });

        it('opens by saying nothing is locked', () => {
            render(<Paywall />);

            expect(
                screen.getByText('One thing left to decide'),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    /Nothing is locked and nothing is about to be/,
                ),
            ).toBeInTheDocument();
        });

        it('promises the money back before it asks for any', () => {
            render(<Paywall />);

            expect(
                screen.getByText('3 days to change your mind'),
            ).toBeInTheDocument();
        });

        it('asks why before walking out, with nothing to confirm', () => {
            mocks.props.canEscapeToFreePlan = true;

            render(<Paywall />);

            fireEvent.click(screen.getByTestId('carry-on-free'));

            // Nothing to disconnect, so nothing to confirm: only the survey.
            expect(screen.getByTestId('free-plan-survey')).toBeInTheDocument();
            expect(mocks.visit).not.toHaveBeenCalled();
            expect(mocks.post).not.toHaveBeenCalled();
        });

        it('walks out to the dashboard when the survey is skipped', () => {
            render(<Paywall />);

            fireEvent.click(screen.getByTestId('carry-on-free'));
            fireEvent.click(screen.getByTestId('free-plan-survey-skip'));

            expect(mocks.visit).toHaveBeenCalledWith('/dashboard');
            expect(mocks.httpPost).not.toHaveBeenCalled();
        });

        it('does not ask twice when walking out has to be retried', () => {
            render(<Paywall />);

            fireEvent.click(screen.getByTestId('carry-on-free'));
            fireEvent.click(screen.getByTestId('free-plan-survey-skip'));
            fireEvent.click(screen.getByTestId('carry-on-free'));

            expect(mocks.visit).toHaveBeenCalledTimes(2);
            expect(
                screen.queryByTestId('free-plan-survey'),
            ).not.toBeInTheDocument();
        });

        it('stores the answer from the soft gate, without a gate, then walks out', async () => {
            render(<Paywall />);

            fireEvent.click(screen.getByTestId('carry-on-free'));
            fireEvent.click(
                screen.getByTestId('free-plan-survey-reason-too_expensive'),
            );

            await act(async () => {
                fireEvent.click(screen.getByTestId('free-plan-survey-send'));
            });

            expect(mocks.httpPost).toHaveBeenCalledWith(
                '/subscribe/free-plan/survey',
                { reason: 'too_expensive', details: '' },
            );
            expect(mocks.visit).toHaveBeenCalledWith('/dashboard');
        });

        it('names what free keeps and what the plan adds', () => {
            render(<Paywall />);

            expect(screen.getByText('Free keeps working')).toBeInTheDocument();
            expect(
                screen.getByText('Standard adds the tedious part'),
            ).toBeInTheDocument();
        });

        it('reports the gate it rendered so the free door can be measured', () => {
            render(<Paywall />);

            expect(mocks.captureEvent).toHaveBeenCalledWith('paywall_viewed', {
                gate: 'soft',
            });

            fireEvent.click(screen.getByTestId('carry-on-free'));

            expect(mocks.captureEvent).toHaveBeenCalledWith(
                'paywall_free_plan_chosen',
            );
        });
    });

    /**
     * Someone who paid and stopped. Unreachable for a new signup, kept for the
     * users who are already in it.
     */
    describe('former subscriber', () => {
        beforeEach(() => {
            mocks.props.canManageConnectionsForFreePlan = true;
        });

        it('says what stopped, when, and what is still theirs', () => {
            render(<Paywall />);

            expect(screen.getByText('Your plan ended')).toBeInTheDocument();
            expect(
                screen.getByText(
                    /Your 3 accounts stopped syncing on August 12/,
                ),
            ).toBeInTheDocument();
            expect(screen.getByText('1,284 movements')).toBeInTheDocument();
            expect(
                screen.getByText('Still here, still categorised'),
            ).toBeInTheDocument();
            expect(screen.getByText('5 rules')).toBeInTheDocument();
        });

        // Losing the date should cost the sentence the date, not the sentence.
        it('still reads when the end date is missing', () => {
            mocks.props.stats = { ...emptyStats, endedAt: null };

            render(<Paywall />);

            expect(
                screen.getByText(
                    /Your 3 accounts stopped syncing\. Everything you imported/,
                ),
            ).toBeInTheDocument();
        });

        it('keeps support reachable and holds the free door shut', () => {
            render(<Paywall />);

            expect(
                screen.getByRole('button', { name: /Need help\?/ }),
            ).toBeInTheDocument();
            expect(
                screen.queryByTestId('carry-on-free'),
            ).not.toBeInTheDocument();
        });

        it('sends the free door through the confirmation, never straight out', () => {
            mocks.props.canEscapeToFreePlan = true;

            render(<Paywall />);

            fireEvent.click(screen.getByTestId('carry-on-free'));

            expect(mocks.post).not.toHaveBeenCalled();
            expect(mocks.visit).toHaveBeenCalledWith('/subscribe/free-plan');
            expect(mocks.captureEvent).toHaveBeenCalledWith(
                'paywall_free_plan_confirm_opened',
                { gate: 'former-subscriber' },
            );
        });
    });
});
