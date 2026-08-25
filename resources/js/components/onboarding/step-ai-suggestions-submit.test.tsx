import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAiSuggestions } from './step-ai-suggestions';

const { get, post, toastError, isRecovering } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    toastError: vi.fn(),
    isRecovering: vi.fn(),
}));

vi.mock('axios', () => ({
    default: { get, post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));

vi.mock('sonner', () => ({ toast: { error: toastError } }));

vi.mock('@/lib/session-expiry-recovery', () => ({
    isRecoveringFromExpiredSession: isRecovering,
}));

const readyState = {
    available: true,
    consented: true,
    requires_upgrade: false,
    eligible: true,
    transaction_count: 4000,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: { id: 'run-1', status: 'completed', suggestions_count: 1 },
    suggestions: [
        {
            id: 'suggestion-1',
            confidence: 0.95,
            match_count: 12,
            summary: 'Mercadona',
            proposed_category: { id: 'category-1', name: 'Groceries' },
            new_category_name: null,
            new_category_direction: null,
            values: [
                {
                    id: 'value-1',
                    match_field: 'description',
                    match_operator: 'contains',
                    match_token: 'MERCADONA',
                },
            ],
        },
    ],
};

async function renderReviewScreen() {
    render(
        <StepAiSuggestions
            categories={[]}
            hasConnectedAccount={false}
            onComplete={vi.fn()}
        />,
    );

    await act(async () => {
        await Promise.resolve();
    });

    return screen.getByRole('button', { name: /Create 1 rules & apply/ });
}

describe('StepAiSuggestions submit failures', () => {
    beforeEach(() => {
        get.mockResolvedValue({ data: readyState });
        isRecovering.mockReturnValue(false);
    });

    afterEach(() => {
        get.mockReset();
        post.mockReset();
        toastError.mockReset();
        isRecovering.mockReset();
    });

    // The reported bug: the catch was empty, so a failed POST left the button
    // looking like it simply did nothing.
    it('tells the user when the rules could not be created', async () => {
        post.mockRejectedValue(new Error('Server Error'));
        const button = await renderReviewScreen();

        await act(async () => {
            button.click();
        });

        expect(toastError).toHaveBeenCalledWith(
            'We could not create your rules. Try again in a moment.',
        );
    });

    // An expired session is already answered by a reload, and "try again in a
    // moment" is the wrong thing to say on the way to the login screen.
    it('stays quiet when the session expired and a reload is coming', async () => {
        post.mockRejectedValue(
            new Error('Request failed with status code 401'),
        );
        isRecovering.mockReturnValue(true);
        const button = await renderReviewScreen();

        await act(async () => {
            button.click();
        });

        expect(toastError).not.toHaveBeenCalled();
    });
});
