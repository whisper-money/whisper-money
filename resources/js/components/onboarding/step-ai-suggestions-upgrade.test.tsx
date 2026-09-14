import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepAiSuggestions } from './step-ai-suggestions';

const UPGRADE_NOTICE =
    "AI suggestions are a paid feature. Enable them and you'll choose a plan at the end of the onboarding.";

// Mutable so each test can flip whether an upgrade is required before render.
const state = {
    available: true,
    consented: false,
    previously_consented: false,
    requires_upgrade: true,
    eligible: true,
    transaction_count: 0,
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

describe('StepAiSuggestions upgrade notice', () => {
    it('warns free users that AI suggestions require a paid plan', async () => {
        state.consented = false;
        state.requires_upgrade = true;
        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                onAddAccount={vi.fn()}
                onComplete={vi.fn()}
            />,
        );

        expect(await screen.findByText(UPGRADE_NOTICE)).toBeInTheDocument();
    });

    it('omits the notice when no upgrade is required', async () => {
        state.consented = false;
        state.requires_upgrade = false;
        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                onAddAccount={vi.fn()}
                onComplete={vi.fn()}
            />,
        );

        expect(await screen.findByText('Turn it on')).toBeInTheDocument();
        expect(screen.queryByText(UPGRADE_NOTICE)).not.toBeInTheDocument();
    });

    it('omits the notice for a paid signup, which still gives consent', async () => {
        state.consented = false;
        state.requires_upgrade = true;
        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                signupPlan="paid"
                onAddAccount={vi.fn()}
                onComplete={vi.fn()}
            />,
        );

        expect(await screen.findByText('Turn it on')).toBeInTheDocument();
        expect(screen.queryByText(UPGRADE_NOTICE)).not.toBeInTheDocument();
    });

    it('keeps the notice for a signup with no plan intent', async () => {
        state.consented = false;
        state.requires_upgrade = true;
        render(
            <StepAiSuggestions
                categories={[]}
                hasConnectedAccount={false}
                signupPlan={null}
                onAddAccount={vi.fn()}
                onComplete={vi.fn()}
            />,
        );

        expect(await screen.findByText(UPGRADE_NOTICE)).toBeInTheDocument();
    });

    it('activates AI directly for users with a connected bank', async () => {
        state.consented = false;
        state.requires_upgrade = false;
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
        expect(screen.queryByText('Turn it on')).not.toBeInTheDocument();
    });
});
