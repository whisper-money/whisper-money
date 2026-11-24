import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { type AiSuggestion } from './ai-suggestion-card';
import { StepAiSuggestions } from './step-ai-suggestions';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock('axios', () => ({
    default: { get, post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    // Everything below the gate belongs to a user who has already paid; the
    // gate itself has its own suite.
    pageProps.auth.hasProPlan = true;

    return {
        router: { reload: vi.fn() },
        usePage: () => ({ props: pageProps }),
    };
});

const suggestion: AiSuggestion = {
    id: 'suggestion-1',
    confidence: 0.95,
    group_size: 84,
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
    transaction_count: 903,
    estimate_count: 0,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: null,
    suggestions: [],
    ...overrides,
});

const onAddAccount = vi.fn();

async function renderStep(
    overrides: Record<string, unknown>,
    { canConnectBank = false }: { canConnectBank?: boolean } = {},
) {
    get.mockResolvedValue({ data: stateWith(overrides) });

    render(
        <StepAiSuggestions
            categories={[]}
            hasConnectedAccount={false}
            canConnectBank={canConnectBank}
            onAddAccount={onAddAccount}
            onComplete={vi.fn()}
        />,
    );

    await act(async () => {
        await Promise.resolve();
    });
}

const completedRun = (count: number) => ({
    id: 'run-1',
    status: 'completed',
    merchants_considered: 147,
    suggestions_count: count,
});

/**
 * The step branches into six screens and only one of them is the happy path.
 * Each one is a state a real user lands on — a short CSV, a revoked consent, a
 * year of one-off purchases — so each gets asserted on what it says.
 */
describe('StepAiSuggestions screens', () => {
    afterEach(() => {
        get.mockReset();
        post.mockReset();
        onAddAccount.mockReset();
    });

    it('asks for consent, without claiming the user ever gave it', async () => {
        await renderStep({ consented: false, previously_consented: false });

        expect(
            screen.getByText('Let AI draft your rules?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('One line at a time, never the picture'),
        ).toBeInTheDocument();
    });

    it('says why it is asking again when the consent was given before', async () => {
        await renderStep({ consented: false, previously_consented: true });

        expect(
            screen.getByText('Turn AI sorting back on?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'You switched this off, or we changed what we send and need you to look again. Here is exactly what it is.',
            ),
        ).toBeInTheDocument();
    });

    it('offers more history to someone with too few movements', async () => {
        await renderStep({ eligible: false, transaction_count: 31 });

        expect(
            screen.getByText('Not enough to learn from yet'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'You have 31 movements. Patterns start showing up around 50 — below that we would be guessing, and a wrong rule is worse than no rule.',
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Add more history first' }),
        );
        expect(onAddAccount).toHaveBeenCalledOnce();
    });

    it('says the AI could not run when the run failed, not that it ran too long', async () => {
        await renderStep({
            run: { ...completedRun(0), status: 'failed' },
        });

        expect(screen.getByText('The AI couldn’t run')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Nothing was changed: your movements are exactly as they were. Try again later, or sort them by hand.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('That didn’t finish'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Usually works on the second run'),
        ).not.toBeInTheDocument();
        expect(screen.getAllByText('Try again')).toHaveLength(1);
        expect(
            screen.getByText('903 movements, at your own pace, whenever'),
        ).toBeInTheDocument();
    });

    it('does not ask for consent when there is too little for the AI to read', async () => {
        await renderStep({
            consented: false,
            previously_consented: false,
            eligible: false,
            transaction_count: 0,
            estimate_count: 4,
        });

        expect(
            screen.queryByText('Let AI draft your rules?'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Not enough to learn from yet'),
        ).toBeInTheDocument();
    });

    it('names typed estimates apart instead of saying there are no movements', async () => {
        await renderStep({
            eligible: false,
            transaction_count: 0,
            estimate_count: 4,
        });

        expect(
            screen.getByText(
                'You have 0 real movements — the estimates you typed are already sorted and don’t count. Patterns start showing up around 50 — below that we would be guessing, and a wrong rule is worse than no rule.',
            ),
        ).toBeInTheDocument();
    });

    it('only mentions the bank when a bank connection is on offer', async () => {
        await renderStep({ eligible: false, transaction_count: 31 });

        expect(screen.getByText('Import another file')).toBeInTheDocument();
        expect(
            screen.queryByText('Another file, or connect the bank'),
        ).not.toBeInTheDocument();
    });

    it('offers the bank alongside a file when one can be connected', async () => {
        await renderStep(
            { eligible: false, transaction_count: 31 },
            { canConnectBank: true },
        );

        expect(
            screen.getByText('Another file, or connect the bank'),
        ).toBeInTheDocument();
    });

    it('does not promise to sort every movement by hand on the consent screen', async () => {
        await renderStep({ consented: false, previously_consented: false });

        expect(screen.queryByText(/903/)).not.toBeInTheDocument();
        expect(screen.getByText('each new movement')).toBeInTheDocument();
    });

    it('does not claim anything was sorted when no rule was worth it', async () => {
        await renderStep({ run: { ...completedRun(0), status: 'empty' } });

        expect(screen.getByText('Nothing worth a rule')).toBeInTheDocument();
        expect(screen.getByText('Nothing was changed')).toBeInTheDocument();
    });

    it('counts the rules on the review screen, not the transactions', async () => {
        await renderStep({
            run: completedRun(1),
            suggestions: [suggestion],
        });

        expect(
            screen.getByText('1 rule, ready when you are'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Apply 1 rule' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Any of them can be undone from the transaction itself.',
            ),
        ).toBeInTheDocument();
    });

    it('drops the apply action when every rule has been unticked', async () => {
        await renderStep({
            run: completedRun(1),
            suggestions: [suggestion],
        });

        fireEvent.click(screen.getByRole('checkbox'));

        expect(
            screen.queryByRole('button', { name: 'Apply 1 rule' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Continue' }),
        ).toBeInTheDocument();
    });
});
