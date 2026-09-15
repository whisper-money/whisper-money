import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { type AiSuggestion } from './ai-suggestion-card';
import { StepAiSuggestions } from './step-ai-suggestions';

const { get, post, reload } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('axios', () => ({
    default: { get, post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload },
    usePage: () => ({ props: {} }),
}));

const suggestion: AiSuggestion = {
    id: 'suggestion-1',
    confidence: 0.95,
    group_size: 12,
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

const stateWith = (overrides: Record<string, unknown>) => ({
    available: true,
    consented: true,
    previously_consented: true,
    requires_upgrade: false,
    eligible: true,
    transaction_count: 4000,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: null,
    suggestions: [],
    ...overrides,
});

const onComplete = vi.fn();

async function renderStep(overrides: Record<string, unknown>) {
    get.mockResolvedValue({ data: stateWith(overrides) });

    render(
        <StepAiSuggestions
            categories={[]}
            hasConnectedAccount={false}
            onAddAccount={vi.fn()}
            onComplete={onComplete}
        />,
    );

    await act(async () => {
        await Promise.resolve();
    });
}

async function click(name: string) {
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name }));
    });
}

/**
 * The step has four ways out — too few transactions, no patterns found, a failed
 * run, and the review screen — and the users behind all of them paid the same.
 * Each has to start the categorization batch on the way out; hanging it off
 * "accepted the rules" alone would leave three of the four reaching the
 * dashboard with nothing categorized.
 */
describe('StepAiSuggestions exits', () => {
    beforeEach(() => {
        post.mockResolvedValue({ data: {} });
    });

    afterEach(() => {
        get.mockReset();
        post.mockReset();
        reload.mockReset();
        onComplete.mockReset();
    });

    const expectCategorizationStarted = () => {
        expect(post).toHaveBeenCalledWith('/onboarding/categorize');
        expect(onComplete).toHaveBeenCalled();
    };

    it('starts categorization when the user has too few transactions', async () => {
        await renderStep({ eligible: false });

        await click('Continue');

        expectCategorizationStarted();
    });

    it('starts categorization when the run found no patterns', async () => {
        await renderStep({
            run: {
                id: 'run-1',
                status: 'empty',
                merchants_considered: 12,
                suggestions_count: 0,
            },
        });

        await click('Continue');

        expectCategorizationStarted();
    });

    it('starts categorization when the run failed', async () => {
        await renderStep({
            run: {
                id: 'run-1',
                status: 'failed',
                merchants_considered: 12,
                suggestions_count: 0,
            },
        });

        await click('Skip for now');

        expectCategorizationStarted();
    });

    it('starts categorization once the suggested rules are created', async () => {
        await renderStep({
            run: {
                id: 'run-1',
                status: 'completed',
                merchants_considered: 12,
                suggestions_count: 1,
            },
            suggestions: [suggestion],
        });
        post.mockResolvedValue({
            data: {
                summary: { rules_created: 1, transactions_categorized: 12 },
                applied_to_existing: true,
            },
        });
        reload.mockImplementation((options: { onFinish: () => void }) =>
            options.onFinish(),
        );

        await click('Apply 1 rule');
        await click('Continue');

        expectCategorizationStarted();
    });

    it('starts categorization when the suggestions are skipped', async () => {
        await renderStep({
            run: {
                id: 'run-1',
                status: 'completed',
                merchants_considered: 12,
                suggestions_count: 1,
            },
            suggestions: [suggestion],
        });

        await click('Skip for now');

        expectCategorizationStarted();
    });
});
