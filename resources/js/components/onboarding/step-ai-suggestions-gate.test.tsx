import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAiSuggestions } from './step-ai-suggestions';

/**
 * Replaces the upgrade-notice suite this file was split out of. That notice
 * told a user with no plan they would "choose a plan at the end of the
 * onboarding"; there is no end-of-onboarding choice to make any more, because
 * the AI cannot be switched on without paying for it first. The gate below is
 * what the notice turned into, so its tests are what these are.
 */
const state = {
    available: true,
    consented: false,
    previously_consented: false,
    requires_upgrade: true,
    eligible: true,
    transaction_count: 903,
    min_transactions: 50,
    auto_select_confidence: 0.8,
    throttled: false,
    throttled_until: null,
    run: null,
    suggestions: [],
};

vi.mock('axios', () => ({
    default: {
        get: () => Promise.resolve({ data: state }),
        post: () => new Promise(() => {}),
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

async function setPlan(hasProPlan: boolean): Promise<void> {
    const { pageProps } = await import('@/lib/onboarding-page-props');
    pageProps.auth.hasProPlan = hasProPlan;
}

async function setAsksToPayOnlyWithData(enabled: boolean): Promise<void> {
    const { pageProps } = await import('@/lib/onboarding-page-props');
    (pageProps as { asksToPayOnlyWithData?: boolean }).asksToPayOnlyWithData =
        enabled;
}

function renderStep(onComplete = vi.fn()) {
    return render(
        <StepAiSuggestions
            categories={[]}
            hasConnectedAccount={false}
            onAddAccount={vi.fn()}
            onComplete={onComplete}
        />,
    );
}

describe('StepAiSuggestions gate', () => {
    beforeEach(async () => {
        state.consented = false;
        state.run = null;
        state.transaction_count = 903;
        await setAsksToPayOnlyWithData(false);
    });

    it('sells the plan to a user who arrived without one', async () => {
        await setPlan(false);

        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                onAddAccount={vi.fn()}
                onComplete={vi.fn()}
            />,
        );

        expect(
            await screen.findByText('AI sorting needs Standard'),
        ).toBeInTheDocument();
        // The count is the argument: this is what it would have to read.
        expect(screen.getByText(/903 movements/)).toBeInTheDocument();
        // The disclosure is the condition for grouping consent with the charge.
        expect(
            screen.getByText('What leaves your account'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/never your balance, your name or your account/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('3 days to change your mind'),
        ).toBeInTheDocument();
    });

    it('leaves the step when the user would rather sort by hand', async () => {
        await setPlan(false);
        const onComplete = vi.fn();

        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                onAddAccount={vi.fn()}
                onComplete={onComplete}
            />,
        );

        (await screen.findByTestId('decline-gate')).click();

        expect(onComplete).toHaveBeenCalled();
    });

    it('never shows the gate to a user who already paid', async () => {
        await setPlan(true);

        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount
                onAddAccount={vi.fn()}
                onComplete={vi.fn()}
            />,
        );

        // Auto-consent runs, so the prompt is skipped and we jump to generating.
        expect(
            await screen.findByText('Reading your merchants'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('AI sorting needs Standard'),
        ).not.toBeInTheDocument();
    });

    it('skips the gate for a user with nothing imported, once Pro waits for data', async () => {
        await setPlan(false);
        await setAsksToPayOnlyWithData(true);
        state.transaction_count = 0;
        const onComplete = vi.fn();

        renderStep(onComplete);

        await vi.waitFor(() => expect(onComplete).toHaveBeenCalled());
        expect(
            screen.queryByText('AI sorting needs Standard'),
        ).not.toBeInTheDocument();
    });

    it('still sells the plan to a user with data, once Pro waits for data', async () => {
        await setPlan(false);
        await setAsksToPayOnlyWithData(true);

        renderStep();

        expect(
            await screen.findByText('AI sorting needs Standard'),
        ).toBeInTheDocument();
    });

    it('keeps the gate for a user with nothing imported while the flag is off', async () => {
        await setPlan(false);
        state.transaction_count = 0;

        renderStep();

        expect(
            await screen.findByText('AI sorting needs Standard'),
        ).toBeInTheDocument();
    });
});
