<?php

namespace App\Features;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A/B assignment for the price experiment: control (the current plan prices) vs
 * a higher price tier. Independent of {@see SubscriptionExperiment} — the hash is
 * salted with a per-experiment prefix so a user's price bucket does not track
 * their trial bucket, keeping the two experiments statistically orthogonal and
 * leaving the salt free for future experiments on the same id.
 *
 * Users who registered before `subscriptions.price_experiment.started_at` (or any
 * user while it is null) are "legacy" and keep the control price. Everyone who
 * registered on or after the start is split evenly by a stable, salted hash of
 * their id, so the bucket never changes for a given user. The funnel report
 * mirrors bucket() in PHP so it always matches what the user was served.
 *
 * @api
 */
class PriceExperiment
{
    public const LEGACY = 'legacy';

    public const CONTROL = 'control';

    public const HIGH = 'high';

    /**
     * In-memory override that pins every user to the winning variant once the
     * experiment is decided. Returning non-null skips both storage and resolve,
     * so flipping PRICE_EXPERIMENT_FORCE_VARIANT rolls the winner out to everyone
     * without a deploy and without rewriting stored assignments.
     */
    public function before(?User $user): ?string
    {
        $forced = config('subscriptions.price_experiment.force_variant');

        return in_array($forced, [self::CONTROL, self::HIGH], true) ? $forced : null;
    }

    public function resolve(?User $user): string
    {
        $startedAt = config('subscriptions.price_experiment.started_at');

        if ($user === null || $startedAt === null || $user->created_at?->lt(CarbonImmutable::parse($startedAt))) {
            return self::LEGACY;
        }

        return self::bucket((string) $user->getKey());
    }

    /**
     * Deterministic, evenly-split bucket for a post-start user. The 'price:' salt
     * decouples this split from every other crc32-based experiment on the same id
     * (e.g. SubscriptionExperiment), so the two arms are independent. Keep this the
     * single source of truth — the funnel report mirrors it to attribute users
     * without reading Pennant per row.
     */
    public static function bucket(string $key): string
    {
        return crc32('price:'.$key) % 2 === 0 ? self::CONTROL : self::HIGH;
    }
}
