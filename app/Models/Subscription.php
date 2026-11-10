<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Cashier's subscription, plus the pause window (see
 * `App\Actions\Subscription\PauseSubscription`).
 *
 * Stripe pauses with `pause_collection`: the subscription stays `active` and
 * the invoices of the paused months are voided. Cashier therefore still calls
 * it subscribed, so whether the plan is on right now is read from the window
 * stored here, never from the Stripe status.
 *
 * `pause_resumes_at` is the renewal that ends the pause, the moment the plan
 * is charged again. Stripe is told to resume collecting a little before it
 * (`STRIPE_RESUME_AHEAD_MINUTES`), so that renewal is collected rather than
 * voided as one more paused month.
 *
 * @property ?CarbonInterface $pause_starts_at
 * @property ?CarbonInterface $pause_resumes_at
 */
class Subscription extends CashierSubscription
{
    /** How long before the renewal that ends a pause Stripe collects again. */
    public const STRIPE_RESUME_AHEAD_MINUTES = 60;

    /**
     * Only the pause window: the rest are Cashier's own `$casts`, merged in.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pause_starts_at' => 'datetime',
            'pause_resumes_at' => 'datetime',
        ];
    }

    /**
     * Whether the subscription is inside its paused window right now: past
     * the end of the period that was paid for, and not yet resumed. No plan
     * meanwhile. A pause with no end (only Stripe's dashboard sets one) lasts
     * until it is lifted.
     */
    public function isPaused(): bool
    {
        return $this->pause_starts_at !== null
            && $this->pause_starts_at->isPast()
            && ($this->pause_resumes_at === null || $this->pause_resumes_at->isFuture());
    }

    /**
     * Whether a pause is set and still to come: the user is in the period
     * they paid for, and keeps the plan until it ends.
     */
    public function hasScheduledPause(): bool
    {
        return $this->pause_starts_at !== null && $this->pause_starts_at->isFuture();
    }

    /**
     * Whether a pause is set at all, running or still to come.
     */
    public function hasPause(): bool
    {
        return $this->isPaused() || $this->hasScheduledPause();
    }

    /**
     * The renewal a pause of so many months ends on, the way Stripe bills a
     * monthly plan: on the billing anchor's day of the month (the last day of
     * a shorter month) and at its time. A plan anchored on the 31st that last
     * renewed on February 28 renews on March 31, not March 28.
     */
    public static function renewalAfter(CarbonInterface $startsAt, int $months, ?CarbonInterface $billingAnchor = null): CarbonImmutable
    {
        $anchor = ($billingAnchor ?? $startsAt)->toImmutable()->utc();
        $month = $startsAt->toImmutable()->utc()->startOfMonth()->addMonthsNoOverflow($months);

        return $month
            ->setDay(min($anchor->day, $month->daysInMonth))
            ->setTime($anchor->hour, $anchor->minute, $anchor->second);
    }

    /**
     * The `resumes_at` Stripe is given for a pause ending on that renewal.
     */
    public static function stripeResumesAt(CarbonInterface $renewal): CarbonImmutable
    {
        return $renewal->toImmutable()->subMinutes(self::STRIPE_RESUME_AHEAD_MINUTES);
    }
}
