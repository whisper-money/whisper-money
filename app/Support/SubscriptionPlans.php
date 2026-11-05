<?php

namespace App\Support;

/**
 * Questions about the plans declared in `config('subscriptions.plans')`.
 */
final class SubscriptionPlans
{
    /**
     * Whether a key names one of the configured plans. A plain key lookup, not
     * `config("subscriptions.plans.{$key}")`: that resolves dot paths, so
     * `yearly.features` would pass for a plan.
     */
    public static function isKnown(string $planKey): bool
    {
        return array_key_exists($planKey, (array) config('subscriptions.plans', []));
    }
}
