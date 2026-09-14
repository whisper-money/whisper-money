import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepComplete } from './step-complete';

const post = vi.fn();

const { captureEvent } = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
}));

const renderStep = () =>
    render(
        <StepComplete
            accountsCreated={2}
            hasConnectedAccount
            signupPlan="paid"
        />,
    );

type VisitCallbacks = {
    onError?: () => void;
    onFinish?: () => void;
    onNetworkError?: (error: Error) => void;
    onSuccess?: () => void;
};

/**
 * Every way `POST /onboarding/complete` can end without moving the user on.
 * Only the first of them reaches `onError`, which is why resetting the button
 * from there left the last step of onboarding behind a dead, spinning button.
 */
const failures: Array<[string, (callbacks: VisitCallbacks) => void]> = [
    [
        'the server rejects the request',
        (callbacks) => {
            callbacks.onError?.();
            callbacks.onFinish?.();
        },
    ],
    [
        'the request never reaches the server',
        (callbacks) => {
            callbacks.onNetworkError?.(new Error('Network error'));
            callbacks.onFinish?.();
        },
    ],
    [
        'the answer is one Inertia cannot read',
        (callbacks) => {
            callbacks.onFinish?.();
        },
    ],
];

describe('StepComplete', () => {
    afterEach(() => {
        post.mockReset();
        captureEvent.mockReset();
    });

    it('forgets the stored resume step once onboarding is done', async () => {
        window.localStorage.setItem('onboarding-step', 'complete');

        renderStep();

        fireEvent.click(screen.getByRole('button'));

        await act(async () => {
            const callbacks = post.mock.calls[0][2] as VisitCallbacks;
            callbacks.onSuccess?.();
            callbacks.onFinish?.();
        });

        // Left behind, it would drag a returning user back into onboarding.
        expect(window.localStorage.getItem('onboarding-step')).toBeNull();
    });

    it.each(failures)(
        'lets the user try again when %s',
        async (_failure, settle) => {
            renderStep();

            fireEvent.click(screen.getByRole('button'));

            expect(post).toHaveBeenCalledOnce();
            expect(screen.getByRole('button')).toBeDisabled();

            await act(async () => {
                settle(post.mock.calls[0][2] as VisitCallbacks);
            });

            // StepButton disables itself while loading, so staying in that
            // state is a one-way door out of onboarding.
            expect(screen.getByRole('button')).toBeEnabled();
        },
    );

    it('reports the completion with what the user set up', async () => {
        renderStep();

        fireEvent.click(screen.getByRole('button'));

        await act(async () => {
            const callbacks = post.mock.calls[0][2] as VisitCallbacks;
            callbacks.onSuccess?.();
            callbacks.onFinish?.();
        });

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith('onboarding_completed', {
            accounts_created: 2,
            has_connected_account: true,
            signup_plan: 'paid',
        });
    });

    // A retryable failure is not a completed onboarding, and counting it as one
    // would put the funnel's last step above the number of onboarded users.
    it.each(failures)('reports nothing when %s', async (_failure, settle) => {
        renderStep();

        fireEvent.click(screen.getByRole('button'));

        await act(async () => {
            settle(post.mock.calls[0][2] as VisitCallbacks);
        });

        expect(captureEvent).not.toHaveBeenCalled();
    });
});
