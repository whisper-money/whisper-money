import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepComplete } from './step-complete';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
}));

type VisitCallbacks = {
    onError?: () => void;
    onFinish?: () => void;
    onNetworkError?: (error: Error) => void;
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
    });

    it.each(failures)(
        'lets the user try again when %s',
        async (_failure, settle) => {
            render(<StepComplete />);

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
});
