import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Success from './success';

const mocks = vi.hoisted(() => ({
    reload: vi.fn(),
    visit: vi.fn(),
    state: { hasProPlan: false, continueUrl: null as string | null },
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { reload: mocks.reload, visit: mocks.visit },
    usePage: () => ({
        props: {
            auth: { hasProPlan: mocks.state.hasProPlan },
            locale: 'en',
            continueUrl: mocks.state.continueUrl,
        },
    }),
}));

describe('Success', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        mocks.state.hasProPlan = false;
        mocks.state.continueUrl = null;
    });

    afterEach(() => vi.useRealTimers());

    it('waits for the subscription to exist before offering the dashboard', () => {
        render(<Success />);

        // Stripe redirects here before its webhook lands, and the dashboard is
        // behind `EnsureUserIsSubscribed` — clicking through too early would
        // bounce a paying customer back to the paywall.
        expect(screen.getByRole('button')).toBeDisabled();

        act(() => vi.advanceTimersByTime(2000));
        expect(mocks.reload).toHaveBeenCalledWith({ only: ['auth'] });
    });

    it('opens up as soon as the plan is really active', () => {
        mocks.state.hasProPlan = true;

        render(<Success />);

        expect(screen.getByRole('button')).toBeEnabled();
        act(() => vi.advanceTimersByTime(10000));
        expect(mocks.reload).not.toHaveBeenCalled();
    });

    it('gives up rather than trapping the user when the webhook never lands', () => {
        render(<Success />);

        // One tick per act() — each poll only schedules the next one after
        // React has re-rendered with the incremented count.
        for (let tick = 0; tick < 6; tick++) {
            act(() => vi.advanceTimersByTime(2000));
        }

        expect(mocks.reload).toHaveBeenCalledTimes(5);
        expect(screen.getByRole('button')).toBeEnabled();
        expect(
            screen.getByText(/Your payment went through/),
        ).toBeInTheDocument();
    });

    // `continueUrl` is non-null exactly when the checkout started mid-wizard.
    // Such a user has nothing connected — they paid in order to connect
    // something — so Settings → Connections is advice about a thing that does
    // not exist yet.
    it('does not send a user who paid mid-onboarding to Settings', () => {
        mocks.state.hasProPlan = true;
        mocks.state.continueUrl = '/onboarding?step=create-account';

        render(<Success />);

        expect(screen.queryByText(/Settings → Connections/)).toBeNull();
        expect(screen.getByText(/Nothing to sync yet/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Pick up where you left off' }),
        ).toBeInTheDocument();
    });

    it('still points a plain checkout at Settings for a faster first sync', () => {
        mocks.state.hasProPlan = true;

        render(<Success />);

        expect(screen.getByText(/Settings → Connections/)).toBeInTheDocument();
    });

    // Most checkouts start a trial and take nothing today, so thanking the
    // reader for a payment Stripe had just priced at €0.00 was answering a
    // different transaction. What happened either way is that the plan exists.
    it('names the plan rather than a payment that may not have happened', () => {
        mocks.state.hasProPlan = true;

        render(<Success />);

        expect(screen.getByText('Your plan is ready')).toBeInTheDocument();
        expect(screen.queryByText('Payment received')).toBeNull();
    });
});
