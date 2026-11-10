<?php

namespace App\Models;

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
 * @property ?CarbonInterface $pause_starts_at
 * @property ?CarbonInterface $pause_resumes_at
 */
class Subscription extends CashierSubscription
{
    protected $casts = [
        'ends_at' => 'datetime',
        'quantity' => 'integer',
        'trial_ends_at' => 'datetime',
        'pause_starts_at' => 'datetime',
        'pause_resumes_at' => 'datetime',
    ];

    /**
     * Whether the subscription is inside its paused window right now: past
     * the end of the period that was paid for, and not yet resumed. No plan
     * meanwhile.
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
}
