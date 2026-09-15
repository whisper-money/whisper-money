import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
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

// The paywall shell renders the app logo, which reads the privacy-mode
// context the real app provides app-wide from app.tsx.
vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { visit: mocks.visit, post: mocks.post },
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
        mocks.props.canUseFreePlan = false;
        mocks.props.canEscapeToFreePlan = false;
        mocks.props.canManageConnectionsForFreePlan = false;
        mocks.props.stats = { ...emptyStats };
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

        it('walks straight out to the dashboard, with nothing to confirm', () => {
            mocks.props.canEscapeToFreePlan = true;

            render(<Paywall />);

            fireEvent.click(screen.getByTestId('carry-on-free'));

            // Nothing to disconnect, so nothing to confirm.
            expect(mocks.visit).toHaveBeenCalledWith('/dashboard');
            expect(mocks.post).not.toHaveBeenCalled();
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
