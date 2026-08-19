import { type PricingConfig } from '@/types/pricing';

/**
 * The shared props the onboarding steps read off usePage(). Both plan-intent
 * test suites mock @inertiajs/react with it, so it lives here rather than twice.
 */
export const pageProps: {
    locale: string;
    subscriptionsEnabled: boolean;
    pricing: PricingConfig;
} = {
    locale: 'en',
    subscriptionsEnabled: true,
    pricing: {
        plans: {
            yearly: {
                name: 'Standard Yearly',
                price: 23.88,
                original_price: 47.88,
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
    },
};
