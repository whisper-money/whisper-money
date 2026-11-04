<?php

namespace App\Actions\Subscription;

use App\Models\User;
use Laravel\Cashier\Subscription;

/**
 * Ends a subscription on the spot, for a user who is leaving.
 *
 * Stripe stops collecting on the subscription's open invoices once it is
 * cancelled, so this is also what ends the retries on a failed payment. A
 * seeded subscription (demo, press, e2e) carries a made-up `stripe_id`, and a
 * user with no Stripe customer has nothing on Stripe's side: both are only
 * marked as cancelled locally, since handing either to Stripe would 404.
 */
class CancelSubscriptionNow
{
    public function handle(User $user, Subscription $subscription): void
    {
        if ($user->hasStripeId() && ! $user->hasSeededSubscription()) {
            $subscription->cancelNow();

            return;
        }

        $subscription->markAsCanceled();
    }
}
