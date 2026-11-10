import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Billing from './billing';

const mocks = vi.hoisted(() => ({
    routerPost: vi.fn(),
    axiosPost: vi.fn(() => Promise.resolve({ data: {} })),
    axiosDelete: vi.fn(() => Promise.resolve({ data: {} })),
    state: {
        hasProPlan: false,
        hasAiConsent: false,
        isSharedAccount: false,
        subscription: null as Record<string, unknown> | null,
        hasUnpaidSubscription: false,
        errors: {} as Record<string, string>,
        defaultPlan: 'yearly',
    },
}));

vi.mock('axios', () => ({
    default: {
        post: mocks.axiosPost,
        delete: mocks.axiosDelete,
        get: vi.fn(),
        isAxiosError: () => false,
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { post: mocks.routerPost, visit: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                isDemoAccount: false,
                isSharedAccount: mocks.state.isSharedAccount,
                hasProPlan: mocks.state.hasProPlan,
            },
            pricing: { ...pricing, defaultPlan: mocks.state.defaultPlan },
            locale: 'en',
            hasAiConsent: mocks.state.hasAiConsent,
            subscription: mocks.state.subscription,
            hasUnpaidSubscription: mocks.state.hasUnpaidSubscription,
            errors: mocks.state.errors,
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

function aiCheckbox(): HTMLElement {
    return screen.getByRole('checkbox', { name: /Allow AI categorization/i });
}

describe('Billing – AI categorization toggle', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.state.hasProPlan = false;
        mocks.state.hasAiConsent = false;
        mocks.state.isSharedAccount = false;
        mocks.state.subscription = null;
    });

    it('prompts a free user to subscribe instead of recording consent', async () => {
        render(<Billing />);

        // Capture the element up front: once the dialog opens, Radix marks the
        // background as aria-hidden, so getByRole can no longer see it.
        const checkbox = aiCheckbox();
        fireEvent.click(checkbox);

        expect(
            await screen.findByText('Let the AI sort your movements'),
        ).toBeInTheDocument();
        // The consent must NOT be recorded — that is what would lock the free
        // user behind the paywall on the next navigation.
        expect(mocks.axiosPost).not.toHaveBeenCalled();
        expect(checkbox).not.toBeChecked();
    });

    it('records consent directly for a subscribed user', async () => {
        mocks.state.hasProPlan = true;
        render(<Billing />);

        fireEvent.click(aiCheckbox());

        await waitFor(() => expect(mocks.axiosPost).toHaveBeenCalledTimes(1));
        expect(
            screen.queryByText('Let the AI sort your movements'),
        ).not.toBeInTheDocument();
    });

    it('offers no consent toggle on a shared account', () => {
        // Its credentials are public, so consent means nothing and the route
        // behind the toggle is blocked — showing it would only hand a
        // journalist a button that fails.
        mocks.state.isSharedAccount = true;
        mocks.state.hasProPlan = true;
        render(<Billing />);

        expect(
            screen.queryByRole('checkbox', {
                name: /Allow AI categorization/i,
            }),
        ).not.toBeInTheDocument();
    });

    it('lets a free user with existing consent revoke it (escape the lock)', async () => {
        mocks.state.hasAiConsent = true;
        render(<Billing />);

        // Unchecking is not gated — it must revoke so a previously locked free
        // user can get out from behind the paywall.
        fireEvent.click(aiCheckbox());

        await waitFor(() => expect(mocks.axiosDelete).toHaveBeenCalledTimes(1));
        expect(mocks.axiosPost).not.toHaveBeenCalled();
        expect(
            screen.queryByText('Let the AI sort your movements'),
        ).not.toBeInTheDocument();
    });
});

describe('Billing – plan status', () => {
    const monthly = { price: 8.99, billing_period: 'month' };

    beforeEach(() => {
        mocks.state.hasProPlan = true;
        mocks.state.hasAiConsent = false;
        mocks.state.isSharedAccount = false;
        mocks.state.subscription = null;
    });

    it('tells a trialing subscriber when the trial ends and what the first charge is', () => {
        mocks.state.subscription = {
            onTrial: true,
            trialEndsAt: '2026-11-05T12:00:00+00:00',
            onGracePeriod: false,
            plan: monthly,
        };
        render(<Billing />);

        expect(
            screen.getByText('Free trial — ends Nov 5, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('First charge of €8.99/month on Nov 5, 2026.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Standard plan active'),
        ).not.toBeInTheDocument();
    });

    it('tells a subscriber who canceled the trial that they will not be charged', () => {
        mocks.state.subscription = {
            onTrial: true,
            trialEndsAt: '2026-11-05T12:00:00+00:00',
            onGracePeriod: true,
            plan: monthly,
        };
        render(<Billing />);

        expect(
            screen.getByText('Free trial — ends Nov 5, 2026'),
        ).toBeInTheDocument();
        expect(screen.getByText(/so you won't be charged/)).toBeInTheDocument();
        expect(screen.queryByText(/First charge/)).not.toBeInTheDocument();
    });

    it('announces no amount when the trial plan could not be resolved', () => {
        mocks.state.subscription = {
            onTrial: true,
            trialEndsAt: '2026-11-05T12:00:00+00:00',
            onGracePeriod: false,
            plan: null,
        };
        render(<Billing />);

        expect(
            screen.getByText('Your first charge will be on Nov 5, 2026.'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/€53.94/)).not.toBeInTheDocument();
    });

    it('shows the price of the plan the subscriber is on', () => {
        mocks.state.subscription = {
            onTrial: false,
            trialEndsAt: null,
            onGracePeriod: false,
            plan: monthly,
        };
        render(<Billing />);

        expect(screen.getByText('Standard plan active')).toBeInTheDocument();
        expect(screen.getByText('— €8.99/month')).toBeInTheDocument();
    });

    it('falls back to the default plan when the plan could not be resolved', () => {
        mocks.state.subscription = {
            onTrial: false,
            trialEndsAt: null,
            onGracePeriod: false,
            plan: null,
        };
        render(<Billing />);

        expect(screen.getByText('— €53.94/year')).toBeInTheDocument();
    });
});

describe('Billing – a subscription whose charge failed', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.state.hasProPlan = false;
        mocks.state.hasAiConsent = false;
        mocks.state.isSharedAccount = false;
        mocks.state.subscription = null;
        mocks.state.hasUnpaidSubscription = true;
        mocks.state.errors = {};
    });

    afterEach(() => {
        mocks.state.hasUnpaidSubscription = false;
        mocks.state.errors = {};
    });

    it('offers to cancel or to update the card instead of a new plan', () => {
        render(<Billing />);

        expect(screen.getByText('Your payment failed')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Cancel subscription' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Update payment method/ }),
        ).toHaveAttribute('href', '/settings/billing/portal');
        expect(screen.queryByText(/Upgrade to Pro/i)).not.toBeInTheDocument();
    });

    it('cancels only once the user confirms', () => {
        render(<Billing />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel subscription' }),
        );
        expect(mocks.routerPost).not.toHaveBeenCalled();

        expect(
            screen.getByText(
                'Your plan ends right away and we stop charging your card.',
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm cancellation' }),
        );

        expect(mocks.routerPost).toHaveBeenCalledTimes(1);
        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/cancel-unpaid',
            {},
            expect.any(Object),
        );
    });

    it('lets the user back out of the cancellation', () => {
        render(<Billing />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel subscription' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep my plan' }));

        expect(mocks.routerPost).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Cancel subscription' }),
        ).toBeInTheDocument();
    });

    it('shows why the cancellation failed', () => {
        mocks.state.errors = {
            subscription: 'We could not cancel your subscription.',
        };
        render(<Billing />);

        expect(
            screen.getByText('We could not cancel your subscription.'),
        ).toBeInTheDocument();
    });

    it('still offers the cancel while Stripe retries a Pro renewal (past_due)', () => {
        mocks.state.hasProPlan = true;
        mocks.state.subscription = {
            onTrial: false,
            trialEndsAt: null,
            onGracePeriod: false,
            plan: { price: 8.99, billing_period: 'month' },
        };
        render(<Billing />);

        expect(screen.getByText('Your payment failed')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Cancel subscription' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Standard plan active')).toBeInTheDocument();
        // The alert already links to the portal; a second button is noise.
        expect(
            screen.queryByRole('link', { name: /Manage Subscription/ }),
        ).not.toBeInTheDocument();
    });

    it('confirms the cancellation once it went through', async () => {
        const { toast } = await import('sonner');
        mocks.routerPost.mockImplementation(
            (_url: string, _data: object, options: { onSuccess: () => void }) =>
                options.onSuccess(),
        );
        render(<Billing />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel subscription' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm cancellation' }),
        );

        expect(toast.success).toHaveBeenCalledWith(
            'Your subscription was canceled.',
        );
    });

    it('hides the cancel on a shared account', () => {
        mocks.state.isSharedAccount = true;
        render(<Billing />);

        expect(
            screen.queryByText('Your payment failed'),
        ).not.toBeInTheDocument();
    });

    it('keeps the upgrade path when nothing is owed', () => {
        mocks.state.hasUnpaidSubscription = false;
        render(<Billing />);

        expect(
            screen.queryByText('Your payment failed'),
        ).not.toBeInTheDocument();
    });
});

describe('Billing – upgrade section', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.state.hasProPlan = false;
        mocks.state.hasAiConsent = false;
        mocks.state.isSharedAccount = false;
        mocks.state.subscription = null;
        mocks.state.hasUnpaidSubscription = false;
        mocks.state.defaultPlan = 'yearly';
    });

    it('preselects the configured default plan and reports it to checkout', () => {
        mocks.state.defaultPlan = 'monthly';
        render(<Billing />);

        const link = screen.getByRole('link', { name: 'Start a plan' });
        expect(link.getAttribute('href')).toMatch(
            /[?&]plan=monthly&preselected_plan=monthly/,
        );
        expect(
            screen.getByText(
                'Free for 7 days, then €8.99 a month. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();
    });

    it('attributes the checkout to the billing settings', () => {
        render(<Billing />);

        expect(
            screen
                .getByRole('link', { name: 'Start a plan' })
                .getAttribute('href'),
        ).toMatch(/[?&]source=billing(&|$)/);
    });

    it('keeps the preselected plan when the user picks the other one', () => {
        mocks.state.defaultPlan = 'monthly';
        render(<Billing />);

        fireEvent.click(screen.getByText('Yearly'));

        expect(
            screen
                .getByRole('link', { name: 'Start a plan' })
                .getAttribute('href'),
        ).toMatch(/[?&]plan=yearly&preselected_plan=monthly/);
    });
});
