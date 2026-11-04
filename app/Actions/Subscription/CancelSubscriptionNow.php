<?php

namespace App\Actions\Subscription;

use App\Models\User;
use Laravel\Cashier\Subscription;
use Stripe\Exception\InvalidRequestException;

/**
 * Ends a subscription on the spot, for a user who is leaving.
 *
 * Stripe stops collecting on the subscription's open invoices once it is
 * cancelled, so this is also what ends the retries on a failed payment. A
 * seeded subscription (demo, press, e2e) carries a made-up `stripe_id`, and a
 * user with no Stripe customer has nothing on Stripe's side: both are only
 * marked as cancelled locally, since handing either to Stripe would 404.
 *
 * A subscription Stripe no longer has (a missed webhook, a cancel in the
 * dashboard) is marked as cancelled locally too: failing on it would leave the
 * user stuck with a row nothing can cancel. Any other Stripe error is thrown.
 */
class CancelSubscriptionNow
{
    public function handle(User $user, Subscription $subscription): void
    {
        if (! $user->hasStripeId() || $user->hasSeededSubscription()) {
            $subscription->markAsCanceled();

            return;
        }

        try {
            $subscription->cancelNow();
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }

            $subscription->markAsCanceled();
        }
    }
}
