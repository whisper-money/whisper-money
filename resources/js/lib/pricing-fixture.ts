import { PricingConfig } from '@/types/pricing';

/**
 * The pricing payload the paid-path tests mock `usePage` with. Three suites
 * (paywall, upgrade dialog, billing) need the same two plans, so it lives here
 * rather than three times — and in `lib/` rather than under `components/`,
 * where the orphan-components check would flag a file no page imports.
 *
 * Mirrors `config/subscriptions.php`'s control arm.
 */
export const pricingFixture: PricingConfig = {
    plans: {
        monthly: {
            name: 'Standard Monthly',
            price: 3.99,
            original_price: null,
            stripe_lookup_key: 'monthly',
            billing_period: 'month',
            trial_days: 7,
            features: [],
        },
        yearly: {
            name: 'Standard Yearly',
            price: 23.88,
            original_price: 47.88,
            stripe_lookup_key: 'yearly',
            billing_period: 'year',
            trial_days: 15,
            features: [],
        },
    },
    defaultPlan: 'yearly',
    bestValuePlan: 'yearly',
    promo: { enabled: false, code: '', description: '', badge: '' },
    currency: 'EUR',
};
