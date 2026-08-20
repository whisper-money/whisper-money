import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
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
 * What Inertia does to a visit whose request never reached the server: it
 * reports the transport failure and settles the chain. `onError` is not part of
 * it — that only runs for a response Inertia could read.
 */
function failOnTheNetwork(callbacks: VisitCallbacks): void {
    callbacks.onNetworkError?.(new Error('Network error'));
    callbacks.onFinish?.();
}

describe('StepComplete', () => {
    it('lets the user try again when the request never reaches the server', async () => {
        render(<StepComplete />);

        fireEvent.click(screen.getByRole('button'));

        expect(screen.getByRole('button')).toBeDisabled();

        const callbacks = post.mock.calls[0]?.[2] as VisitCallbacks;

        await act(async () => {
            failOnTheNetwork(callbacks);
        });

        // StepButton disables itself while loading, so staying in that state
        // leaves the last step of onboarding behind a dead button.
        expect(screen.getByRole('button')).toBeEnabled();
    });
});
