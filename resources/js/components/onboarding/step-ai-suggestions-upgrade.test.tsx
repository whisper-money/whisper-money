import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepAiSuggestions } from './step-ai-suggestions';

const UPGRADE_NOTICE =
    "AI suggestions are a Standard Plan feature. You'll choose a plan at the end of the onboarding.";

// Mutable so each test can flip whether an upgrade is required before render.
const state = {
    available: true,
    consented: false,
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

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({
        props: {
            locale: 'en',
            pricing: {
                plans: {
                    yearly: {
                        name: 'Standard Yearly',
                        price: 23.88,
                        original_price: 47.88,
                        stripe_lookup_key: null,
                        billing_period: 'year',
                        features: [],
                    },
                },
                defaultPlan: 'yearly',
                bestValuePlan: 'yearly',
                promo: { enabled: false, code: '', description: '', badge: '' },
                currency: 'EUR',
            },
        },
    }),
}));

describe('StepAiSuggestions upgrade notice', () => {
    it('warns free users that AI suggestions require a paid plan', async () => {
        state.requires_upgrade = true;
        render(<StepAiSuggestions categories={[]} onComplete={vi.fn()} />);

        expect(await screen.findByText(UPGRADE_NOTICE)).toBeInTheDocument();
    });

    it('omits the notice when no upgrade is required', async () => {
        state.requires_upgrade = false;
        render(<StepAiSuggestions categories={[]} onComplete={vi.fn()} />);

        expect(
            await screen.findByText('Suggest my rules with AI'),
        ).toBeInTheDocument();
        expect(screen.queryByText(UPGRADE_NOTICE)).not.toBeInTheDocument();
    });
});
