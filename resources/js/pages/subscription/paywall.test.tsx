import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Paywall from './paywall';

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
        mocks.props.stats = {
            accountsCount: 3,
            transactionsCount: 1284,
            categoriesCount: 12,
        };
    });

    it('starts on the configured default plan and carries it into checkout', () => {
        render(<Paywall />);

        expect(
            screen.getByRole('link', { name: /Start a plan/ }),
        ).toHaveAttribute('href', expect.stringContaining('plan=yearly'));
    });

    it('carries the plan the user picks into checkout', () => {
        render(<Paywall />);

        fireEvent.click(screen.getByText('Monthly'));

        expect(
            screen.getByRole('link', { name: /Start a plan/ }),
        ).toHaveAttribute('href', expect.stringContaining('plan=monthly'));
    });

    it('renders the price the server hands over, per experiment arm', () => {
        render(<Paywall />);

        // Whatever the arm, the page states the monthly equivalent and the real
        // yearly charge rather than a price of its own.
        expect(
            screen.getByText('€1.99/month, billed as €23.88 a year'),
        ).toBeInTheDocument();
        expect(screen.getByText('€3.99 a month')).toBeInTheDocument();
    });

    it('names what the user already has when the gate is hard', () => {
        render(<Paywall />);

        expect(
            screen.getByText(/Your 3 accounts and 1,284 transactions/),
        ).toBeInTheDocument();
    });

    it('drops the snapshot sentence rather than saying zero', () => {
        mocks.props.stats = {
            accountsCount: 1,
            transactionsCount: 0,
            categoriesCount: 0,
        };

        render(<Paywall />);

        expect(screen.queryByText(/are already here/)).not.toBeInTheDocument();
    });

    it('offers support and no free door while the hard gate still holds', () => {
        render(<Paywall />);

        expect(
            screen.getByRole('button', { name: /Need help\?/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Continue with the free plan'),
        ).not.toBeInTheDocument();
    });

    it('opens the free door on a hard gate once the delay has passed', async () => {
        mocks.props.canEscapeToFreePlan = true;

        render(<Paywall />);

        // Support stays reachable: giving up the banks is not the only thing a
        // stuck user might want.
        expect(
            screen.getByRole('button', { name: /Need help\?/ }),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue with the free plan',
            }),
        );

        // Nothing leaves until the consequences have been read and confirmed.
        expect(mocks.post).not.toHaveBeenCalled();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'paywall_free_plan_confirm_opened',
            { gate: 'hard' },
        );

        expect(
            await screen.findByText(
                'Your connected banks are disconnected and stop syncing.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/every transaction already imported stay/),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Disconnect and continue' }),
        );

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'paywall_free_plan_confirmed',
            { gate: 'hard' },
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/subscribe/free-plan',
            {},
            expect.anything(),
        );
    });

    it('never puts the confirmation in front of a soft gate', () => {
        mocks.props.canUseFreePlan = true;
        mocks.props.canEscapeToFreePlan = true;

        render(<Paywall />);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue with the free plan',
            }),
        );

        // Nothing to disconnect, so nothing to confirm.
        expect(mocks.visit).toHaveBeenCalledWith('/dashboard');
        expect(mocks.post).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('offers the free door immediately on a soft gate, with no timer', () => {
        mocks.props.canUseFreePlan = true;

        render(<Paywall />);

        const free = screen.getByRole('button', {
            name: 'Continue with the free plan',
        });
        expect(free).toBeInTheDocument();

        fireEvent.click(free);
        expect(mocks.visit).toHaveBeenCalledWith('/dashboard');
    });

    it('states the amount and the date next to the button', () => {
        render(<Paywall />);

        // The commitment line has to stand on its own: the selected row can be
        // scrolled off, or sit behind the sticky footer.
        expect(
            screen.getByText(
                'Free for 15 days, then €23.88 a year. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByText('Monthly'));

        expect(
            screen.getByText(
                'Free for 7 days, then €3.99 a month. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();
    });

    it('only promises features that are actually gated', () => {
        render(<Paywall />);

        // The three cases of App\Enums\PlanFeature — and nothing about limits,
        // which the free plan does not have either.
        expect(screen.getByText('Connected banks')).toBeInTheDocument();
        expect(screen.getByText('AI suggestions')).toBeInTheDocument();
        expect(screen.getByText('Your AI assistant')).toBeInTheDocument();
        expect(screen.queryByText(/No limits/)).not.toBeInTheDocument();
    });

    it('reports the gate it rendered so the free door can be measured', () => {
        mocks.props.canUseFreePlan = true;

        render(<Paywall />);

        expect(mocks.captureEvent).toHaveBeenCalledWith('paywall_viewed', {
            gate: 'soft',
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue with the free plan',
            }),
        );

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'paywall_free_plan_chosen',
        );
    });

    it('offers the disconnect route to a former subscriber', () => {
        mocks.props.canManageConnectionsForFreePlan = true;

        render(<Paywall />);

        expect(screen.getByText('Your plan has ended')).toBeInTheDocument();
        expect(screen.getByText('Disconnect your banks')).toBeInTheDocument();
        // A former subscriber is not sold the product again.
        expect(screen.queryByText('Your AI assistant')).not.toBeInTheDocument();
    });

    it('offers the free door to a former subscriber as well', () => {
        mocks.props.canManageConnectionsForFreePlan = true;
        mocks.props.canEscapeToFreePlan = true;

        render(<Paywall />);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue with the free plan',
            }),
        );

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'paywall_free_plan_confirm_opened',
            { gate: 'former-subscriber' },
        );
    });
});
