<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendInactiveNoBankEmailJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SendInactiveNoBankEmailsCommand extends Command
{
    protected $signature = 'email:inactive-no-bank';

    protected $description = 'Queue the re-engagement email for onboarded users with no bank connected who last used the app a week ago';

    /**
     * A user is treated as dormant once this many days have passed since their
     * last visit. The match is on that exact day, so each dormancy episode
     * triggers a single email.
     */
    private const INACTIVE_DAYS = 7;

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $dormantOn = today()->subDays(self::INACTIVE_DAYS)->toDateString();

        $queued = 0;

        User::query()
            ->excludingSharedAccounts()
            ->whereNotNull('onboarded_at')
            ->whereDate('last_active_at', $dormantOn)
            ->whereDoesntHave('bankingConnections')
            ->whereDoesntHave('mailLogs', function ($query) use ($dormantOn): void {
                $query->where('email_type', DripEmailType::InactiveNoBank)
                    ->where('email_identifier', $dormantOn);
            })
            ->chunkById(100, function (Collection $users) use ($dormantOn, &$queued): void {
                foreach ($users as $user) {
                    SendInactiveNoBankEmailJob::dispatch($user, $dormantOn);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} inactive-no-bank email(s).");

        return self::SUCCESS;
    }
}
