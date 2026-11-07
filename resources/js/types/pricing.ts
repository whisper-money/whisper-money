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
    /** Days after the charge in which the refund can still be self-served. */
    refundWindowDays: number;
    /**
     * The regional latam price, present only while it is offered
     * (`SUBSCRIPTION_LATAM_PRICING`). Pick the tier to show through
     * `offeredPriceTier()`, never by reading this directly.
     */
    latam?: LatamPricing;
    /**
     * The tier a signed-in user is offered (`User::offeredPriceTier()`): `latam`
     * or the configured one. Null for an anonymous visitor; absent while the
     * latam price is not offered.
     */
    offeredTier?: string | null;
}

export interface LatamPricing {
    plans: Record<string, Plan>;
    /**
     * The timezones the backend places in Latin America
     * (`PricingRegion::latamTimezones()`), for an anonymous visitor only.
     */
    timezones: string[] | null;
}

/**
 * Which landing pricing card a user registered from. `free` hides the paid
 * options during onboarding, `paid` drops the "you'll choose a plan later"
 * warnings, and null — every other entry point — leaves the flow untouched.
 *
 * Mirrors App\Enums\SignupPlan.
 */
export type SignupPlan = 'free' | 'paid';
