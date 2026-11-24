<?php

namespace App\Services\Subscriptions;

use App\Actions\Subscription\ExtendTrial;
use App\Models\Subscription;
use App\Models\User;

/**
 * The trial extension for someone who already cancelled during their trial,
 * as the `trial_ending_canceled` email offers it: two more weeks and the plan
 * back on, so the first charge moves to the new trial end.
 *
 * The cancel flow's offer (`CancellationOffers::extendTrial()`) works on a
 * subscription that is not set to cancel yet; this one is for the opposite,
 * a trial on Cashier's grace period. Only what is stored here is checked, so
 * the email and the page that confirms it never reach Stripe; taking it asks
 * Stripe too, through `CancellationOffers::extendTrial()`.
 */
class CanceledTrialExtension
{
    public function __construct(private ExtendTrial $extendTrial) {}

    /**
     * The user's own `default` subscription when it is a trial set to cancel
     * at its end that may still be extended, or null.
     */
    public function subscriptionFor(User $user): ?Subscription
    {
        $subscription = $user->subscription('default');

        if (
            ! $subscription instanceof Subscription
            || ! $subscription->onGracePeriod()
            || $subscription->hasIncompletePayment()
            || ! $this->extendTrial->isAvailable($user, $subscription)
        ) {
            return null;
        }

        return $subscription;
    }
}
