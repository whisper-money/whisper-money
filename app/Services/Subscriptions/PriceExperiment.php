<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A/B split on the price of the paid plan: `control` keeps the plans.* prices,
 * `high` swaps in the variant tier. Users who registered before `started_at` —
 * and everyone while it is null — are `legacy` and pay the control price, so
 * this is inert until the experiment is switched on.
 *
 * ponytail: the assignment is a pure salted hash of the user id, not a stored
 * Pennant feature. Nothing has to be persisted, read back or purged afterwards,
 * and a report can reproduce the split in SQL with CRC32(CONCAT('price:', id)).
 * Move it to Pennant only if an experiment ever needs a non-deterministic or
 * hand-overridden per-user assignment.
 */
class PriceExperiment
{
    public const LEGACY = 'legacy';

    public const CONTROL = 'control';

    public const HIGH = 'high';

    /**
     * The 'price:' salt decouples this split from any other crc32-based split on
     * the same user id, so experiments never share buckets.
     */
    public static function variantFor(User $user): string
    {
        $forced = config('subscriptions.price_experiment.force_variant');

        if (in_array($forced, [self::CONTROL, self::HIGH], true)) {
            return $forced;
        }

        $startedAt = config('subscriptions.price_experiment.started_at');

        if ($startedAt === null || $user->created_at?->lt(CarbonImmutable::parse($startedAt))) {
            return self::LEGACY;
        }

        return crc32('price:'.$user->getKey()) % 2 === 0 ? self::CONTROL : self::HIGH;
    }

    /**
     * The plans config with the user's variant applied: price, original_price and
     * Stripe lookup key. Feeds both the shared pricing prop and checkout, so what
     * is shown is always what is charged. Guests get the control config.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function plansFor(?User $user): array
    {
        $plans = (array) config('subscriptions.plans', []);

        if ($user === null) {
            return $plans;
        }

        $overrides = (array) config('subscriptions.price_experiment.variants.'.self::variantFor($user), []);

        foreach ($overrides as $planKey => $override) {
            if (! isset($plans[$planKey])) {
                continue;
            }

            $plans[$planKey]['price'] = $override['price'];
            $plans[$planKey]['original_price'] = $override['original_price'] ?? null;
            $plans[$planKey]['stripe_lookup_key'] = $override['lookup'];
        }

        return $plans;
    }

    /**
     * Stripe lookup key to charge for a plan, resolved from the user's variant
     * server-side and never from the request, so nobody can pick the cheap price.
     */
    public static function lookupKeyFor(User $user, string $planKey): string
    {
        return (string) (self::plansFor($user)[$planKey]['stripe_lookup_key'] ?? '');
    }
}
