import { type PricingConfig } from '@/types/pricing';

/**
 * The shared props the onboarding steps read off usePage(). Both plan-intent
 * test suites mock @inertiajs/react with it, so it lives here rather than twice.
 */
export const pageProps: {
    locale: string;
    subscriptionsEnabled: boolean;
    openBankingEnabled: boolean;
    auth: { hasProPlan: boolean };
    pricing: PricingConfig;
} = {
    locale: 'en',
    subscriptionsEnabled: true,
    // Overwritten per test: a self-hosted install with no EnableBanking
    // credentials has no bank to offer, only brokers and the manual form.
    openBankingEnabled: true,
    // Overwritten per test: whether there is a plan behind the user decides
    // whether a step shows its gate or the thing the gate is in front of.
    auth: { hasProPlan: false },
    pricing: {
        plans: {
            yearly: {
                name: 'Standard Yearly',
                price: 53.94,
                original_price: 107.88,
                stripe_lookup_key: null,
                billing_period: 'year',
                trial_days: 0,
                features: [],
            },
        },
        defaultPlan: 'yearly',
        bestValuePlan: 'yearly',
        promo: { enabled: false, code: '', description: '', badge: '' },
        currency: 'EUR',
        refundWindowDays: 3,
    },
};
