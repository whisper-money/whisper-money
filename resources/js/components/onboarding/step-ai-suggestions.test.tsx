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

const runningState = {
    available: true,
    consented: true,
    previously_consented: true,
    requires_upgrade: false,
    eligible: true,
    transaction_count: 903,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: {
        id: 'run-1',
        status: 'processing',
        merchants_considered: 147,
        suggestions_count: 23,
    },
    suggestions: [],
};

function renderStep() {
    render(
        <StepAiSuggestions
            categories={[]}
            hasConnectedAccount={false}
            onAddAccount={vi.fn()}
            onComplete={vi.fn()}
        />,
    );
}

/**
 * The screen counts what the run has actually reached — merchants grouped,
 * rules drafted — instead of narrating progress it cannot see, and it promises
 * the couple of minutes the job can really take rather than the four seconds it
 * usually takes.
 */
describe('StepAiSuggestions generating state', () => {
    beforeEach(() => {
        get.mockResolvedValue({ data: runningState });
    });

    afterEach(() => {
        get.mockReset();
        post.mockReset();
    });

    it('counts the merchants and the rules the run has reached', async () => {
        renderStep();

        await act(async () => {
            await Promise.resolve();
        });

        expect(screen.getByText('Merchants found')).toBeInTheDocument();
        expect(screen.getByText('147')).toBeInTheDocument();
        expect(screen.getByText('Rules drafted')).toBeInTheDocument();
        expect(screen.getByText('23')).toBeInTheDocument();
        expect(
            screen.getByText(
                '903 movements, grouped by who you paid. This is the part that saves you an afternoon.',
            ),
        ).toBeInTheDocument();
    });

    it('says how long it can take, and that leaving does not stop it', async () => {
        renderStep();

        await act(async () => {
            await Promise.resolve();
        });

        expect(
            screen.getByText(
                'Up to a couple of minutes. You can leave this screen — it keeps going without you.',
            ),
        ).toBeInTheDocument();
    });

    it('starts at zero while the run has not grouped anything yet', () => {
        // The first state request is still in flight: no run, no counts, and
        // still no spinner pretending to know more than it does.
        get.mockReturnValue(new Promise(() => {}));

        renderStep();

        expect(screen.getAllByText('0')).toHaveLength(2);
    });
});
