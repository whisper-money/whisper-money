<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendInactiveNoBankEmailJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SendInactiveNoBankEmailsCommand extends SendInactiveUserEmailsCommand
{
    protected $signature = 'email:inactive-no-bank';

    protected $description = 'Queue the re-engagement email for onboarded users with no bank connected who last used the app a week ago';

    protected function inactiveDays(): int
    {
        return 7;
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::InactiveNoBank;
    }

    protected function scopeAudience(Builder $query): void
    {
        $query->whereDoesntHave('bankingConnections');
    }

    protected function dispatchFor(User $user, string $dormantOn): void
    {
        SendInactiveNoBankEmailJob::dispatch($user, $dormantOn);
    }
}
