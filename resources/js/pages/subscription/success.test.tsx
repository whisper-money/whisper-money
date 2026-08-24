import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Success from './success';

const mocks = vi.hoisted(() => ({
    reload: vi.fn(),
    visit: vi.fn(),
    state: { hasProPlan: false },
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
        props: { auth: { hasProPlan: mocks.state.hasProPlan }, locale: 'en' },
    }),
}));

describe('Success', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
        mocks.state.hasProPlan = false;
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
});
