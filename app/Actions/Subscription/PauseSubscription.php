<?php

namespace App\Actions\Subscription;

use App\Models\Subscription;
use Carbon\CarbonInterface;
use Stripe\Exception\ApiErrorException;

/**
 * Pauses a paid monthly subscription for a few months, the cancel flow's
 * offer for "I'm not using it enough".
 *
 * Stripe's `pause_collection` with `behavior: void`: the subscription stays
 * `active` and every invoice created while paused is voided, so nothing is
 * charged. Setting it now leaves the period already paid for untouched (its
 * invoice is behind us), so the pause effectively starts when that period
 * ends, and the user keeps the plan until then. Pausing by status needs
 * Stripe's flexible billing mode, which our subscriptions do not use.
 *
 * Stripe resumes collecting just before the renewal that ends the pause, so
 * that renewal is charged (see `Subscription::stripeResumesAt()`).
 */
class PauseSubscription
{
    /**
     * @param  CarbonInterface  $startsAt  The end of the period already paid for.
     * @param  CarbonInterface  $resumesAt  The renewal that ends the pause.
     *
     * @throws ApiErrorException
     */
    public function handle(Subscription $subscription, CarbonInterface $startsAt, CarbonInterface $resumesAt): void
    {
        $subscription->updateStripeSubscription([
            'pause_collection' => [
                'behavior' => 'void',
                'resumes_at' => Subscription::stripeResumesAt($resumesAt)->getTimestamp(),
            ],
        ]);

        $subscription->forceFill([
            'pause_starts_at' => $startsAt,
            'pause_resumes_at' => $resumesAt,
        ])->save();
    }
}
