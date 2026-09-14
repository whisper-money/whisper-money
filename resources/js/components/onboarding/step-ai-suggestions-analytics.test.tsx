import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { type AiSuggestion } from './ai-suggestion-card';
import { StepAiSuggestions } from './step-ai-suggestions';

const { captureEvent } = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

const suggestion: AiSuggestion = {
    id: 'suggestion-1',
    confidence: 0.9,
    group_size: 42,
    sample_descriptions: [],
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
};

// Mutable so each test can put the step in the branch it needs before render.
const state = {
    available: true,
    consented: false,
    previously_consented: false,
    requires_upgrade: false,
    eligible: true,
    transaction_count: 120,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: null as {
        id: string;
        status: string;
        merchants_considered: number;
        suggestions_count: number;
    } | null,
    suggestions: [] as AiSuggestion[],
};

vi.mock('axios', () => ({
    default: {
        get: () => Promise.resolve({ data: state }),
        post: () => Promise.resolve({ data: state }),
        isAxiosError: () => false,
    },
}));

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return {
        router: { reload: vi.fn() },
        usePage: () => ({ props: pageProps }),
    };
});

function renderStep(onComplete = vi.fn()) {
    render(
        <StepAiSuggestions
            categories={[]}
            hasConnectedAccount={false}
            onAddAccount={vi.fn()}
            onComplete={onComplete}
        />,
    );

    return onComplete;
}

describe('StepAiSuggestions analytics', () => {
    afterEach(() => {
        captureEvent.mockReset();
        state.consented = false;
        state.run = null;
        state.suggestions = [];
    });

    it('reports consent given, once it is stored', async () => {
        renderStep();

        fireEvent.click(await screen.findByText('Turn it on'));
        // The consent is reported after the request that stores it settles.
        await act(async () => {});

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith('onboarding_ai_consent', {
            granted: true,
        });
    });

    // The step the whole free/paid split hangs on: a decline has to be visible
    // as a decision, not as a user who silently vanished.
    it('reports consent refused, and still moves on', async () => {
        const onComplete = renderStep();

        fireEvent.click(await screen.findByText('Leave it off'));

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith('onboarding_ai_consent', {
            granted: false,
        });
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('reports a skip after a generation that failed', async () => {
        state.consented = true;
        state.run = {
            id: 'run-1',
            status: 'failed',
            merchants_considered: 12,
            suggestions_count: 0,
        };

        const onComplete = renderStep();

        fireEvent.click(await screen.findByText('Skip for now'));

        expect(captureEvent).toHaveBeenCalledWith('onboarding_step_skipped', {
            step: 'ai-suggestions',
            reason: 'generation_failed',
        });
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('reports walking away from suggestions that were shown', async () => {
        state.consented = true;
        state.run = {
            id: 'run-1',
            status: 'completed',
            merchants_considered: 12,
            suggestions_count: 1,
        };
        state.suggestions = [suggestion];

        const onComplete = renderStep();

        fireEvent.click(await screen.findByText('Skip for now'));

        expect(captureEvent).toHaveBeenCalledWith('onboarding_step_skipped', {
            step: 'ai-suggestions',
            reason: 'suggestions_shown',
        });
        expect(onComplete).toHaveBeenCalledOnce();
    });
});
