<?php

namespace App\Services\Subscriptions;

use App\Models\Subscription;
use App\Models\User;

/**
 * The pause on a user's plan, the way the billing page, the paused screen and
 * the upgrade dialogs show it: when it starts, when it resumes, and at what
 * price.
 */
class SubscriptionPauseDetails
{
    public function __construct(private SubscribedPlan $subscribedPlan) {}

    /**
     * Null when no pause is set, running or still to come.
     *
     * @return array{active: bool, startsAt: ?string, resumesAt: ?string, plan: array{price: float, billing_period: string}|null}|null
     */
    public function for(User $user): ?array
    {
        $subscription = $user->subscription('default');

        if (! $subscription instanceof Subscription || ! $subscription->valid() || ! $subscription->hasPause()) {
            return null;
        }

        return [
            'active' => $subscription->isPaused(),
            'startsAt' => $subscription->pause_starts_at?->toIso8601String(),
            'resumesAt' => $subscription->pause_resumes_at?->toIso8601String(),
            'plan' => $this->subscribedPlan->for($user, $subscription),
        ];
    }
}
