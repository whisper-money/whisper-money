<?php

namespace App\Features;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A/B/C assignment for the trial/pricing experiment.
 *
 * Users who registered before `subscriptions.experiment.started_at` (or any user
 * while it is null) are "legacy" and behave like the control group. Everyone who
 * registered on or after the start is split evenly into the three variants by a
 * stable hash of their id, so the bucket never changes for a given user.
 *
 * The split is deterministic (crc32(id) % 3) and persisted by Pennant; the funnel
 * report reads that persisted value so it always matches what the user was served.
 *
 * @api
 */
class SubscriptionExperiment
{
    public const LEGACY = 'legacy';

    public const CONTROL = 'control';

    public const REDUCED_TRIAL = 'reduced_trial';

    public const PAY_NOW = 'pay_now';

    /**
     * In-memory override that pins every user to the winning variant once the
     * experiment is decided. Returning non-null skips both storage and resolve,
     * so flipping SUBSCRIPTION_EXPERIMENT_FORCE_VARIANT rolls the winner out to
     * everyone without a deploy and without rewriting stored assignments.
     */
    public function before(?User $user): ?string
    {
        $forced = config('subscriptions.experiment.force_variant');

        return in_array($forced, [self::CONTROL, self::REDUCED_TRIAL, self::PAY_NOW], true)
            ? $forced
            : null;
    }

    public function resolve(?User $user): string
    {
        $startedAt = config('subscriptions.experiment.started_at');

        if ($user === null || $startedAt === null || $user->created_at?->lt(CarbonImmutable::parse($startedAt))) {
            return self::LEGACY;
        }

        return self::bucket((string) $user->getKey());
    }

    /**
     * Deterministic, evenly-split bucket for a post-start user. The funnel report
     * mirrors this in PHP to attribute users without reading Pennant per row, so
     * keep the formula here as the single source of truth.
     */
    public static function bucket(string $key): string
    {
        return match (crc32($key) % 3) {
            0 => self::CONTROL,
            1 => self::REDUCED_TRIAL,
            default => self::PAY_NOW,
        };
    }
}
