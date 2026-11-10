<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendInactiveBankedEmailJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SendInactiveBankedEmailsCommand extends SendInactiveUserEmailsCommand
{
    protected $signature = 'email:inactive-banked';

    protected $description = 'Queue the "while you were away" recap for Pro users with a bank connected who last used the app two weeks ago';

    /**
     * Twice the no-bank wait: a connected bank keeps the numbers fresh on its
     * own, so a quiet week is normal and only a second one reads as drifting
     * away.
     */
    protected function inactiveDays(): int
    {
        return 14;
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::InactiveBanked;
    }

    protected function scopeAudience(Builder $query): void
    {
        $query->whereHas('bankingConnections')->with('subscriptions');
    }

    /**
     * Only the users Pro is paying for, or about to: they are the ones who can
     * still cancel.
     */
    protected function isEligible(User $user): bool
    {
        return $user->hasProPlan();
    }

    protected function dispatchFor(User $user, string $dormantOn): void
    {
        SendInactiveBankedEmailJob::dispatch($user, $dormantOn);
    }
}
