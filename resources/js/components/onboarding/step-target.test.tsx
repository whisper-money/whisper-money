import { type OnboardingSummary } from '@/hooks/use-onboarding-summary';
import { onboardingSummaryResponse } from '@/hooks/use-onboarding-summary.fixture';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepTarget } from './step-target';

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

const { get, post, captureEvent } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('axios', () => ({
    default: { get, post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

/** The same user, before they have set the target this step is about. */
function summary(overrides: Partial<OnboardingSummary> = {}) {
    return onboardingSummaryResponse({ target: null, ...overrides });
}

async function renderTarget(props: Record<string, unknown> = {}) {
    const onContinue = vi.fn();

    render(<StepTarget onContinue={onContinue} {...props} />);

    await act(async () => {
        await Promise.resolve();
    });

    return onContinue;
}

describe('StepTarget', () => {
    afterEach(() => {
        get.mockReset();
        post.mockReset();
        captureEvent.mockReset();
    });

    it('opens on a share of what the user actually spends', async () => {
        get.mockResolvedValue(summary());

        await renderTarget({ spendingGuess: 120000 });

        // A tenth of €1,847, rounded to the stepper's own coarseness.
        expect(screen.getByText('200')).toBeInTheDocument();
        // Nothing is put aside: the step caps the month's spending, and says
        // so with the limit the budget will actually hold.
        expect(screen.getByText('less spending a month')).toBeInTheDocument();
        expect(screen.queryByText(/put aside/)).toBeNull();
        expect(screen.getByText('Monthly spending limit')).toBeInTheDocument();
        expect(screen.getAllByText('€1,647')).not.toHaveLength(0);
        // Built on the real month, set against the guess that opened the flow.
        expect(
            screen.getByText(/that left the account last month/),
        ).toBeInTheDocument();
        expect(screen.getByText('€1,847')).toBeInTheDocument();
        expect(screen.getByText('€1,200')).toBeInTheDocument();
    });

    it('moves the target in both directions and keeps the limit with it', async () => {
        get.mockResolvedValue(summary());

        await renderTarget();

        fireEvent.click(
            screen.getByRole('button', { name: 'Raise the target' }),
        );

        expect(screen.getByText('250')).toBeInTheDocument();
        expect(screen.getAllByText('€1,597')).not.toHaveLength(0);

        fireEvent.click(
            screen.getByRole('button', { name: 'Lower the target' }),
        );

        expect(screen.getByText('200')).toBeInTheDocument();
    });

    // The stepper may not walk past what the user has, in either direction.
    it('stops at the floor and at what the month can hold', async () => {
        get.mockResolvedValue(summary({ monthly_spending: 5000 }));

        await renderTarget();

        // €50 spent: the floor and the ceiling are the same single step.
        expect(screen.getByText('50')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Lower the target' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Raise the target' }),
        ).toBeDisabled();
    });

    it('sets the target with the warning the toggle is showing', async () => {
        get.mockResolvedValue(summary());
        post.mockResolvedValue({ data: { target: 20000 } });

        const onContinue = await renderTarget();

        fireEvent.click(
            screen.getByRole('button', { name: /Warn me before I go over it/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Set my target' }));

        await act(async () => {
            await Promise.resolve();
        });

        expect(post).toHaveBeenCalledWith('/onboarding/target', {
            amount: 20000,
            warn: false,
        });
        expect(captureEvent).toHaveBeenCalledWith('onboarding_target_set', {
            amount: 20000,
            spending: 184700,
            warn: false,
        });
        expect(onContinue).toHaveBeenCalledOnce();
    });

    // The target is optional, and this sits right after the user paid: the way
    // out must cost one tap and write nothing.
    it('lets the user decline without saving anything', async () => {
        get.mockResolvedValue(summary());

        const onContinue = await renderTarget();

        fireEvent.click(screen.getByRole('button', { name: 'Not yet' }));

        expect(post).not.toHaveBeenCalled();
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_target_declined',
            {
                spending: 184700,
            },
        );
        expect(onContinue).toHaveBeenCalledOnce();
    });

    it('offers the target again when saving it failed', async () => {
        get.mockResolvedValue(summary());
        post.mockRejectedValue(new Error('down'));

        const onContinue = await renderTarget();

        fireEvent.click(screen.getByRole('button', { name: 'Set my target' }));

        await act(async () => {
            await Promise.resolve();
        });

        expect(onContinue).not.toHaveBeenCalled();
        expect(
            screen.getByText(
                'That target did not save. Try again, or carry on without one.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Set my target' }),
        ).toBeEnabled();
    });

    /**
     * Someone who added a pension and a mortgage has no month to build on, so
     * there is no number to offer. Step 5 promised them this screen, though —
     * "your first target comes out of it" — and vanishing between the step
     * before and the step after reads as one that broke.
     */
    it('says why there is no target rather than disappearing', async () => {
        get.mockResolvedValue(summary({ monthly_spending: null }));

        const onContinue = await renderTarget();

        expect(onContinue).not.toHaveBeenCalled();
        expect(screen.queryByText('Set my target')).toBeNull();
        expect(screen.getByText('Your target can wait')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(onContinue).toHaveBeenCalledOnce();
    });

    it('steps aside when the summary cannot be read at all', async () => {
        get.mockRejectedValue(new Error('down'));

        const onContinue = await renderTarget();

        expect(onContinue).toHaveBeenCalledOnce();
    });

    // Step 2 promised this answer would decide the first target, so it has to
    // be visible in it.
    it.each([
        ['debt', 'Every month you hold it is a month off what you owe.'],
        ['save-for', 'Small enough to keep, and it adds up on its own.'],
        ['understand', 'A number to hold it against, now that you can see it.'],
        [undefined, 'Start small enough to keep.'],
    ])(
        'frames the target around the goal picked in step 2 (%s)',
        async (goal, sentence) => {
            get.mockResolvedValue(summary());

            await renderTarget({ goal });

            expect(
                screen.getByText(sentence, { exact: false }),
            ).toBeInTheDocument();
        },
    );

    // Against the whole month, which is what the catch-all limit covers: a
    // share of fixed charges like rent was a share nobody can cut.
    it('measures the target against the month it was built on', async () => {
        get.mockResolvedValue(summary());

        await renderTarget();

        // €200 of €1,847.
        expect(screen.getByText('11%')).toBeInTheDocument();
        expect(screen.queryByText(/merchants bill you/)).toBeNull();
        expect(
            screen.getByText(/We'll warn you before your spending goes over/),
        ).toBeInTheDocument();
    });

    it('promises no warning once the user turned it off', async () => {
        get.mockResolvedValue(summary());

        await renderTarget();

        fireEvent.click(
            screen.getByRole('button', { name: /Warn me before I go over it/ }),
        );

        expect(screen.queryByText(/We'll warn you/)).toBeNull();
        expect(
            screen.getByText(
                /You can follow it, and change it, from your budgets\./,
            ),
        ).toBeInTheDocument();
    });

    // Quick-expense estimates never left any account: they are what the user
    // typed in, and the sentence says so.
    it.each([
        [120000, 'you typed for last month, not the'],
        [undefined, 'you typed for last month.'],
    ])(
        'calls typed estimates the user own figures (guess %s)',
        async (spendingGuess, fragment) => {
            get.mockResolvedValue(summary({ spending_is_estimate: true }));

            await renderTarget({ spendingGuess });

            expect(
                screen.getByText(new RegExp(fragment.replace('.', '\\.'))),
            ).toBeInTheDocument();
            expect(screen.queryByText(/left the account/)).toBeNull();
        },
    );

    it('opens on the target already set when the step is returned to', async () => {
        get.mockResolvedValue(summary({ target: 35000 }));

        await renderTarget();

        expect(screen.getByText('350')).toBeInTheDocument();
    });
});
