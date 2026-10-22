import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Billing from './billing';

const mocks = vi.hoisted(() => ({
    axiosPost: vi.fn(() => Promise.resolve({ data: {} })),
    axiosDelete: vi.fn(() => Promise.resolve({ data: {} })),
    state: {
        hasProPlan: false,
        hasAiConsent: false,
        isSharedAccount: false,
        subscription: null as Record<string, unknown> | null,
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
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                isDemoAccount: false,
                isSharedAccount: mocks.state.isSharedAccount,
                hasProPlan: mocks.state.hasProPlan,
            },
            pricing,
            locale: 'en',
            hasAiConsent: mocks.state.hasAiConsent,
            subscription: mocks.state.subscription,
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
            await screen.findByText('AI categorization is a paid feature'),
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
            screen.queryByText('AI categorization is a paid feature'),
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
            screen.queryByText('AI categorization is a paid feature'),
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
            endsAt: null,
            plan: monthly,
        };
        render(<Billing />);

        expect(
            screen.getByText('Free trial — ends Nov 5, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('First charge of €8.99/month on Nov 5, 2026.'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Pro Plan Active')).not.toBeInTheDocument();
    });

    it('tells a subscriber who canceled the trial that they will not be charged', () => {
        mocks.state.subscription = {
            onTrial: true,
            trialEndsAt: '2026-11-05T12:00:00+00:00',
            onGracePeriod: true,
            endsAt: '2026-11-05T12:00:00+00:00',
            plan: monthly,
        };
        render(<Billing />);

        expect(
            screen.getByText('Free trial — ends Nov 5, 2026'),
        ).toBeInTheDocument();
        expect(screen.getByText(/so you won't be charged/)).toBeInTheDocument();
        expect(screen.queryByText(/First charge/)).not.toBeInTheDocument();
    });

    it('shows the price of the plan the subscriber is on', () => {
        mocks.state.subscription = {
            onTrial: false,
            trialEndsAt: null,
            onGracePeriod: false,
            endsAt: null,
            plan: monthly,
        };
        render(<Billing />);

        expect(screen.getByText('Pro Plan Active')).toBeInTheDocument();
        expect(screen.getByText('— €8.99/month')).toBeInTheDocument();
    });

    it('falls back to the default plan when the plan could not be resolved', () => {
        mocks.state.subscription = {
            onTrial: false,
            trialEndsAt: null,
            onGracePeriod: false,
            endsAt: null,
            plan: null,
        };
        render(<Billing />);

        expect(screen.getByText('— €53.94/year')).toBeInTheDocument();
    });
});
