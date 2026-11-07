import { type Plan, type PricingConfig } from '@/types/pricing';

/**
 * The two prices a screen can quote: the regional one for Latin America, which
 * leaves out bank connections, and the standard one (whatever tier the app is
 * configured at).
 */
export type PriceTier = 'latam' | 'standard';

/**
 * The tier to quote first. A signed-in user gets the one the server assigned
 * them at signup; an anonymous visitor is placed by their browser's timezone,
 * checked against the list the server shares, so the rule lives only in
 * `App\Support\PricingRegion`. Nobody is quoted latam while the server does
 * not offer it — it then shares no latam data at all.
 *
 * Only what is *shown*: checkout re-checks the tier on the server.
 */
export function offeredPriceTier(
    pricing: PricingConfig,
    isSignedIn: boolean,
    timezone: string | null,
): PriceTier {
    if (!pricing.latam) {
        return 'standard';
    }

    if (isSignedIn) {
        return pricing.offeredTier === 'latam' ? 'latam' : 'standard';
    }

    return timezone !== null &&
        (pricing.latam.timezones ?? []).includes(timezone)
        ? 'latam'
        : 'standard';
}

/** The plans quoted at a tier, keyed like `pricing.plans`. */
export function plansForTier(
    pricing: PricingConfig,
    tier: PriceTier,
): Record<string, Plan> {
    return tier === 'latam' && pricing.latam
        ? pricing.latam.plans
        : pricing.plans;
}
