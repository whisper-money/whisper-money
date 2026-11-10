import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Billing from './billing';

const mocks = vi.hoisted(() => ({
    routerPost: vi.fn(),
    captureEvent: vi.fn(),
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
    Link: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    useHttp: () => ({
        data: { reason: '', details: '' },
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
    }),
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

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

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

describe('Billing – a plan set to cancel', () => {
    const monthly = { price: 8.99, billing_period: 'month' };

    beforeEach(() => {
        vi.clearAllMocks();
        mocks.routerPost.mockReset();
        mocks.state.hasProPlan = true;
        mocks.state.hasAiConsent = false;
        mocks.state.isSharedAccount = false;
        mocks.state.hasUnpaidSubscription = false;
        mocks.state.errors = {};
    });

    afterEach(() => {
        mocks.state.subscription = null;
        mocks.state.isSharedAccount = false;
        mocks.state.errors = {};
    });

    const canceledTrial = {
        onTrial: true,
        trialEndsAt: '2026-11-05T12:00:00+00:00',
        onGracePeriod: true,
        endsAt: '2026-11-05T12:00:00+00:00',
        plan: monthly,
        standardUpgrade: null,
    };

    it('tells a canceled trial when Pro stops and offers to keep it', () => {
        mocks.state.subscription = canceledTrial;
        render(<Billing />);

        expect(
            screen.getByText('Standard stops on Nov 5, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/your first charge is on Nov 5, 2026/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Keep my plan' }),
        ).toBeInTheDocument();
    });

    it('tells a canceled paid plan when it ends and offers to keep it', () => {
        mocks.state.subscription = {
            ...canceledTrial,
            onTrial: false,
            trialEndsAt: null,
            endsAt: '2026-12-20T12:00:00+00:00',
        };
        render(<Billing />);

        expect(
            screen.getByText('Standard stops on Dec 20, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/You won't be charged again/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Keep my plan' }),
        ).toBeInTheDocument();
    });

    it('resumes the plan and reports the click and the success', () => {
        mocks.state.subscription = canceledTrial;
        render(<Billing />);

        fireEvent.click(screen.getByRole('button', { name: 'Keep my plan' }));

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'subscription_resume_clicked',
            { on_trial: true },
        );
        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/resume',
            {},
            expect.any(Object),
        );

        const options = mocks.routerPost.mock.calls[0][2] as {
            onSuccess: (page: { props: unknown }) => void;
        };
        options.onSuccess({ props: { flash: { success: 'Back on' } } });

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'subscription_resumed',
            { on_trial: true },
        );
    });

    it('reports a resume Stripe refused', () => {
        mocks.state.subscription = canceledTrial;
        render(<Billing />);

        fireEvent.click(screen.getByRole('button', { name: 'Keep my plan' }));
        const options = mocks.routerPost.mock.calls[0][2] as {
            onError: () => void;
        };
        options.onError();

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'subscription_resume_failed',
            { on_trial: true },
        );
        expect(mocks.captureEvent).not.toHaveBeenCalledWith(
            'subscription_resumed',
            expect.anything(),
        );
    });

    it('shows why the plan could not be kept', () => {
        mocks.state.subscription = canceledTrial;
        mocks.state.errors = { resume: 'Stripe is down' };
        render(<Billing />);

        expect(screen.getByText('Stripe is down')).toBeInTheDocument();
    });

    it('offers nothing to resume on a running plan', () => {
        mocks.state.subscription = {
            ...canceledTrial,
            onGracePeriod: false,
            endsAt: null,
        };
        render(<Billing />);

        expect(
            screen.queryByRole('button', { name: 'Keep my plan' }),
        ).not.toBeInTheDocument();
    });

    it('offers nothing to resume on a shared account', () => {
        mocks.state.subscription = canceledTrial;
        mocks.state.isSharedAccount = true;
        render(<Billing />);

        expect(
            screen.queryByRole('button', { name: 'Keep my plan' }),
        ).not.toBeInTheDocument();
    });
});

describe('Billing – billing portal analytics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.state.hasProPlan = true;
        mocks.state.isSharedAccount = false;
        mocks.state.hasUnpaidSubscription = false;
        mocks.state.subscription = null;
    });

    it('reports opening the portal from Manage Subscription', () => {
        render(<Billing />);

        const link = screen.getByRole('link', { name: /Manage Subscription/ });
        // jsdom cannot follow it; only the click matters here.
        link.addEventListener('click', (event) => event.preventDefault());
        fireEvent.click(link);

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'billing_portal_opened',
            { source: 'manage' },
        );
    });
});

describe('Billing – the in-app cancel flow', () => {
    const running = {
        onTrial: true,
        trialEndsAt: '2026-11-25T12:00:00+00:00',
        onGracePeriod: false,
        endsAt: null,
        plan: { price: 8.99, billing_period: 'month' },
        standardUpgrade: null,
    };

    beforeEach(() => {
        vi.clearAllMocks();
        mocks.state.hasProPlan = true;
        mocks.state.isSharedAccount = false;
        mocks.state.hasUnpaidSubscription = false;
        mocks.state.errors = {};
    });

    it('opens from the billing page and reports that it started', () => {
        mocks.state.subscription = { ...running, canCancel: true };
        render(<Billing />);

        fireEvent.click(screen.getByTestId('cancel-subscription'));

        expect(screen.getByTestId('cancel-flow')).toBeInTheDocument();
        expect(mocks.captureEvent).toHaveBeenCalledWith('cancel_flow_started', {
            on_trial: true,
        });
        // The portal stays, for the card and the invoices.
        expect(screen.getByText('Manage Subscription')).toBeInTheDocument();
    });

    it('is not offered when the plan cannot be cancelled from here', () => {
        mocks.state.subscription = { ...running, canCancel: false };
        render(<Billing />);

        expect(
            screen.queryByTestId('cancel-subscription'),
        ).not.toBeInTheDocument();
    });

    it('is not offered on a shared account', () => {
        mocks.state.isSharedAccount = true;
        mocks.state.subscription = { ...running, canCancel: true };
        render(<Billing />);

        expect(
            screen.queryByTestId('cancel-subscription'),
        ).not.toBeInTheDocument();
    });
});

describe('Billing – a paused plan', () => {
    const paid = {
        onTrial: false,
        trialEndsAt: null,
        onGracePeriod: false,
        endsAt: null,
        plan: { price: 8.99, billing_period: 'month' },
        standardUpgrade: null,
        canCancel: true,
    };
    const running = {
        active: true,
        startsAt: '2026-11-01T10:00:00+00:00',
        resumesAt: '2026-12-25T09:00:00+00:00',
        plan: { price: 8.99, billing_period: 'month' },
    };

    beforeEach(() => {
        vi.clearAllMocks();
        mocks.routerPost.mockReset();
        mocks.state.hasAiConsent = false;
        mocks.state.isSharedAccount = false;
        mocks.state.hasUnpaidSubscription = false;
        mocks.state.errors = {};
    });

    afterEach(() => {
        mocks.state.subscription = null;
    });

    it('says until when, at what price it resumes, and what resuming now costs', () => {
        mocks.state.hasProPlan = false;
        mocks.state.subscription = { ...paid, pause: running };
        render(<Billing />);

        expect(
            screen.getByText('Paused until Dec 25, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /resumes on its own on Dec 25, 2026 at €8\.99 a month/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Resuming now charges €8\.99 today/),
        ).toBeInTheDocument();
        expect(screen.getByTestId('resume-paused-plan')).toHaveTextContent(
            'Resume now',
        );
        // Nothing to sell: no checkout, but the portal and the cancel flow stay.
        expect(
            screen.queryByText(/Upgrade to Standard/),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Manage Subscription')).toBeInTheDocument();
        expect(screen.getByTestId('cancel-subscription')).toBeInTheDocument();
    });

    it('resumes now and reports it once the server did', () => {
        mocks.state.hasProPlan = false;
        mocks.state.subscription = { ...paid, pause: running };
        mocks.routerPost.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess?: (page: unknown) => void },
            ) => options.onSuccess?.({ props: { flash: {}, errors: {} } }),
        );
        render(<Billing />);

        fireEvent.click(screen.getByTestId('resume-paused-plan'));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/pause/resume',
            {},
            expect.anything(),
        );
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'subscription_pause_resumed_early',
            { source: 'billing', was_paused: true },
        );
    });

    it('does not report a resume the server refused', () => {
        mocks.state.hasProPlan = false;
        mocks.state.subscription = { ...paid, pause: running };
        mocks.routerPost.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess?: (page: unknown) => void },
            ) =>
                options.onSuccess?.({
                    props: { flash: {}, errors: { pause: 'declined' } },
                }),
        );
        render(<Billing />);

        fireEvent.click(screen.getByTestId('resume-paused-plan'));

        expect(mocks.captureEvent).not.toHaveBeenCalledWith(
            'subscription_pause_resumed_early',
            expect.anything(),
        );
    });

    it('shows a pause still to come next to the running plan, and lets it be dropped', () => {
        mocks.state.hasProPlan = true;
        mocks.state.subscription = {
            ...paid,
            pause: {
                ...running,
                active: false,
                startsAt: '2026-11-25T10:00:00+00:00',
            },
        };
        render(<Billing />);

        expect(
            screen.getByText('Your plan pauses on Nov 25, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Standard stays on until Nov 25, 2026/),
        ).toBeInTheDocument();
        expect(screen.getByTestId('resume-paused-plan')).toHaveTextContent(
            'Cancel the pause',
        );
        expect(screen.getByText('Your Standard plan')).toBeInTheDocument();
    });

    it('shows the error the server sent back', () => {
        mocks.state.hasProPlan = false;
        mocks.state.subscription = { ...paid, pause: running };
        mocks.state.errors = { pause: 'We could not charge your card.' };
        render(<Billing />);

        expect(
            screen.getByText('We could not charge your card.'),
        ).toBeInTheDocument();
    });
});
