import { PricingConfig } from '@/types/pricing';

/**
 * The pricing payload the paid-path tests mock `usePage` with. Three suites
 * (paywall, upgrade dialog, billing) need the same two plans, so it lives here
 * rather than three times — and in `lib/` rather than under `components/`,
 * where the orphan-components check would flag a file no page imports.
 *
 * Mirrors `config/subscriptions.php`.
 */
export const pricingFixture: PricingConfig = {
    plans: {
        monthly: {
            name: 'Standard Monthly',
            price: 8.99,
            original_price: null,
            stripe_lookup_key: 'monthly',
            billing_period: 'month',
            trial_days: 0,
            features: [],
        },
        yearly: {
            name: 'Standard Yearly',
            price: 53.94,
            original_price: 107.88,
            stripe_lookup_key: 'yearly',
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
};
