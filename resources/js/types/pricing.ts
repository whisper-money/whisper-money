export interface Plan {
    name: string;
    price: number;
    original_price: number | null;
    stripe_lookup_key: string | null;
    billing_period: 'month' | 'year' | null;
    trial_days: number;
    features: string[];
}

export interface PromoConfig {
    enabled: boolean;
    code: string;
    description: string;
    badge: string;
}

export interface PricingConfig {
    plans: Record<string, Plan>;
    defaultPlan: string;
    bestValuePlan: string | null;
    promo: PromoConfig;
    currency: string;
}

/**
 * Which landing pricing card a user registered from. `free` hides the paid
 * options during onboarding, `paid` drops the "you'll choose a plan later"
 * warnings, and null — every other entry point — leaves the flow untouched.
 *
 * Mirrors App\Enums\SignupPlan.
 */
export type SignupPlan = 'free' | 'paid';
