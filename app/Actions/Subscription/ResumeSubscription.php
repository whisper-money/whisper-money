<?php

namespace App\Actions\Subscription;

use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * Takes back a cancellation the user scheduled. Cashier clears
 * `cancel_at_period_end` on Stripe, keeps the trial end a trial already had,
 * and wipes `ends_at` locally.
 */
class ResumeSubscription
{
    /**
     * Cashier does not declare it, but the update it sends goes straight to
     * Stripe and throws whatever Stripe answers with.
     *
     * @throws ApiErrorException
     */
    public function handle(Subscription $subscription): void
    {
        $subscription->resume();
    }
}
