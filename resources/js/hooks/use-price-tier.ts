import {
    offeredPriceTier,
    plansForTier,
    type PriceTier,
} from '@/lib/price-tier';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { usePage } from '@inertiajs/react';
import { useState, useSyncExternalStore } from 'react';

const noSubscription = () => () => {};

/**
 * The browser's timezone, or null while rendering on the server and during
 * hydration: the server's own timezone says nothing about the visitor, and
 * quoting with it would make the hydrated markup disagree with the server's.
 */
function useBrowserTimezone(): string | null {
    return useSyncExternalStore(
        noSubscription,
        () => Intl.DateTimeFormat().resolvedOptions().timeZone || null,
        () => null,
    );
}

/** The tier to quote this user or visitor first (see `offeredPriceTier`). */
export function useOfferedPriceTier(): PriceTier {
    return useOffered().tier;
}

function useOffered(): { tier: PriceTier; isPending: boolean } {
    const { pricing, auth } = usePage<SharedData>().props;
    const timezone = useBrowserTimezone();
    const isSignedIn = Boolean(auth?.user);

    return {
        tier: offeredPriceTier(pricing, isSignedIn, timezone),
        // Only an anonymous visitor's tier waits on the browser, and only
        // while there is a latam price to place them in.
        isPending: Boolean(pricing.latam) && !isSignedIn && timezone === null,
    };
}

export interface PriceTierChoice {
    /** The tier on screen. */
    tier: PriceTier;
    /** Its plans, keyed like `pricing.plans`. */
    plans: Record<string, Plan>;
    /**
     * Whether the user may switch between the two: only someone offered latam,
     * who can still buy the standard price to get bank connections.
     */
    canSwitch: boolean;
    setTier: (tier: PriceTier) => void;
    /**
     * Whether the tier is still unknown: an anonymous visitor's, before
     * hydration tells us their timezone.
     */
    isPending: boolean;
}

/**
 * The tier a plan picker shows, starting on the offered one and switchable to
 * standard by whoever is offered latam.
 *
 * - `requiresStandard` pins it to standard, for a screen selling what only
 *   standard includes — a bank connection.
 * - `startOnStandard` only opens on standard, for a screen that is mostly
 *   about banks but whose feature the latam price may also cover; the switch
 *   to latam stays.
 */
export function usePriceTierChoice({
    requiresStandard = false,
    startOnStandard = false,
}: {
    requiresStandard?: boolean;
    startOnStandard?: boolean;
} = {}): PriceTierChoice {
    const { pricing } = usePage<SharedData>().props;
    const offered = useOffered();
    // Null until the user picks: the offered tier can still change after
    // hydration, when the browser's timezone becomes known.
    const [chosen, setTier] = useState<PriceTier | null>(null);
    const initial = startOnStandard ? 'standard' : offered.tier;
    const tier = requiresStandard ? 'standard' : (chosen ?? initial);

    return {
        tier,
        plans: plansForTier(pricing, tier),
        canSwitch: offered.tier === 'latam' && !requiresStandard,
        setTier,
        isPending: offered.isPending,
    };
}
