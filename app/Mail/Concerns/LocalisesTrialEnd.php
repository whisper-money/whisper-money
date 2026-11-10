<?php

namespace App\Mail\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The trial end date as the reader's calendar has it.
 *
 * Stripe reports the trial end in UTC, which can land on the previous or next
 * day for the reader. Both write paths validate the stored timezone, so an
 * unparseable one only comes from legacy rows and falls back to UTC.
 *
 * @property User $user
 * @property CarbonImmutable $trialEndsAt
 */
trait LocalisesTrialEnd
{
    private function localTrialEnd(): CarbonImmutable
    {
        return rescue(
            fn () => $this->trialEndsAt->setTimezone($this->user->timezone ?? config('app.timezone')),
            $this->trialEndsAt,
            report: false,
        );
    }
}
