<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;

/**
 * The self-service money-back window: whether a user can still claim it, and
 * when it closes. Shared by the billing screen, the refund endpoint and the
 * sandbox verification command so all three agree on the same deadline.
 */
class RefundWindow
{
    /**
     * Whether the user can still self-refund.
     *
     * Eligibility is read off the subscription, not off the plans: a
     * subscription with no `trial_ends_at` was charged in full at signup and has
     * a refund to claim, whatever `SUBSCRIPTION_PAY_NOW` says today. Reading the
     * config instead would take the window away from everyone who paid upfront
     * the moment a trial is put back on the plans — they were charged, the money
     * is real, and the button has to survive the switch moving under them.
     */
    public function isOpenFor(User $user): bool
    {
        $subscription = $user->subscription('default');

        if ($subscription === null || $subscription->trial_ends_at !== null) {
            return false;
        }

        if ($subscription->refunded_at !== null || ! $subscription->active()) {
            return false;
        }

        if ($user->hasSeededSubscription()) {
            return false;
        }

        return $this->deadlineFor($subscription)->isFuture();
    }

    public function deadlineFor(Subscription $subscription): CarbonImmutable
    {
        return CarbonImmutable::parse($subscription->created_at)
            ->addDays((int) config('subscriptions.refund_window_days', 3));
    }
}
