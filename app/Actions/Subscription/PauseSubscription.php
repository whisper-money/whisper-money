<?php

namespace App\Actions\Subscription;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
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
 * Collection resumes a little before the renewal that ends the pause, so the
 * invoice created at that renewal is collected rather than voided as one more
 * paused month.
 */
class PauseSubscription
{
    /** How long before the renewal Stripe starts collecting again. */
    private const RESUME_AHEAD_MINUTES = 60;

    /**
     * @param  CarbonInterface  $startsAt  The end of the period already paid for.
     *
     * @throws ApiErrorException
     */
    public function handle(Subscription $subscription, CarbonInterface $startsAt, int $months): void
    {
        $resumesAt = self::resumesAt($startsAt, $months);

        $subscription->updateStripeSubscription([
            'pause_collection' => [
                'behavior' => 'void',
                'resumes_at' => $resumesAt->getTimestamp(),
            ],
        ]);

        $subscription->forceFill([
            'pause_starts_at' => $startsAt,
            'pause_resumes_at' => $resumesAt,
        ])->save();
    }

    /**
     * When a pause of so many months from the given day hands the plan back:
     * just before the renewal that many months on.
     */
    public static function resumesAt(CarbonInterface $startsAt, int $months): CarbonImmutable
    {
        return $startsAt->toImmutable()
            ->addMonthsNoOverflow($months)
            ->subMinutes(self::RESUME_AHEAD_MINUTES);
    }
}
