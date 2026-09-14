<?php

namespace App\Features;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Assignment for the subscription experiment.
 *
 * Users who registered before `subscriptions.experiment.started_at` (or any user
 * while it is null, or while no variant is declared) are "legacy" and behave
 * like the plan defaults. Everyone who registered on or after the start is split
 * evenly across the configured variants by a stable hash of their id, so the
 * bucket never changes for a given user.
 *
 * The split is deterministic (crc32(id) % variants) and persisted by Pennant;
 * the funnel report reads the same formula so it always matches what the user
 * was served.
 *
 * @api
 */
class SubscriptionExperiment
{
    public const LEGACY = 'legacy';

    /**
     * In-memory override that skips both storage and resolve. It pins every user
     * to the winning variant once the experiment is decided, so flipping
     * SUBSCRIPTION_EXPERIMENT_FORCE_VARIANT rolls the winner out to everyone
     * without a deploy and without rewriting stored assignments. It also answers
     * "legacy" while no experiment is declared, so a dormant instrument writes no
     * assignment rows at all.
     */
    public function before(?User $user): ?string
    {
        $variants = self::variants();

        if ($variants === []) {
            return self::LEGACY;
        }

        $forced = config('subscriptions.experiment.force_variant');

        return in_array($forced, $variants, true) ? $forced : null;
    }

    public function resolve(?User $user): string
    {
        $startedAt = config('subscriptions.experiment.started_at');

        if ($user === null || $startedAt === null) {
            return self::LEGACY;
        }

        if ($user->created_at?->lt(CarbonImmutable::parse($startedAt))) {
            return self::LEGACY;
        }

        return self::bucket((string) $user->getKey());
    }

    /**
     * The declared variant keys, in config order — the order the split depends on.
     *
     * @return list<string>
     */
    public static function variants(): array
    {
        return array_keys((array) config('subscriptions.experiment.variants', []));
    }

    /**
     * Deterministic, evenly-split bucket for a post-start user. The funnel report
     * mirrors this in PHP to attribute users without reading Pennant per row, so
     * keep the formula here as the single source of truth.
     */
    public static function bucket(string $key): string
    {
        $variants = self::variants();

        return $variants === [] ? self::LEGACY : $variants[crc32($key) % count($variants)];
    }
}
