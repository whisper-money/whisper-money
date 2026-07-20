import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAiSuggestions } from './step-ai-suggestions';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock('axios', () => ({
    default: { get, post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
}));

const processingState = {
    available: true,
    consented: true,
    requires_upgrade: false,
    eligible: true,
    transaction_count: 4000,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: { id: 'run-1', status: 'processing', suggestions_count: 0 },
    suggestions: [],
};

describe('StepAiSuggestions polling timeout', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockResolvedValue({ data: processingState });
    });

    afterEach(() => {
        vi.useRealTimers();
        get.mockReset();
        post.mockReset();
    });

    it('stops spinning and shows the failed screen when the run never finishes in time', async () => {
        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                onComplete={vi.fn()}
            />,
        );

        // Spins while the run stays "processing".
        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(
            screen.getByText('This can take up to two minutes.'),
        ).toBeInTheDocument();

        // Past the client-side deadline it gives up instead of polling forever.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(3 * 60_000 + 3000);
        });

        expect(
            screen.getByText('We couldn’t generate suggestions'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('This can take up to two minutes.'),
        ).not.toBeInTheDocument();
    });
});
