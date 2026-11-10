<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared selection behind the `email:inactive-*` commands: onboarded users
 * whose last visit was exactly {@see inactiveDays()} days ago and who have not
 * had this email for that dormancy episode yet. Matching the exact day means
 * each episode triggers a single email.
 */
abstract class SendInactiveUserEmailsCommand extends Command
{
    /**
     * Days since the last visit that make a user dormant for this email.
     */
    abstract protected function inactiveDays(): int;

    abstract protected function emailType(): DripEmailType;

    /**
     * Narrows the dormant users down to this email's audience.
     *
     * @param  Builder<User>  $query
     */
    abstract protected function scopeAudience(Builder $query): void;

    /**
     * Queues the send for one dormant user.
     *
     * @param  string  $dormantOn  the Y-m-d the user was last active
     */
    abstract protected function dispatchFor(User $user, string $dormantOn): void;

    /**
     * A per-user check the query cannot express cheaply.
     */
    protected function isEligible(User $user): bool
    {
        return true;
    }

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $dormantOn = today()->subDays($this->inactiveDays())->toDateString();

        $queued = 0;

        User::query()
            ->excludingSharedAccounts()
            ->whereNotNull('onboarded_at')
            ->whereDate('last_active_at', $dormantOn)
            ->tap(fn (Builder $query) => $this->scopeAudience($query))
            ->whereDoesntHave('mailLogs', function ($query) use ($dormantOn): void {
                $query->where('email_type', $this->emailType())
                    ->where('email_identifier', $dormantOn);
            })
            ->chunkById(100, function (Collection $users) use ($dormantOn, &$queued): void {
                foreach ($users as $user) {
                    if (! $this->isEligible($user)) {
                        continue;
                    }

                    $this->dispatchFor($user, $dormantOn);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} {$this->emailType()->value} email(s).");

        return self::SUCCESS;
    }
}
