<?php

namespace App\Console\Commands;

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendPaywallFollowUpEmailJob;
use App\Models\User;
use Illuminate\Console\Command;

class SendPaywallFollowUpEmailsCommand extends Command
{
    protected $signature = 'email:paywall-follow-up';

    protected $description = 'Queue the paywall follow-up email for users who completed onboarding yesterday, saw the paywall and did not subscribe';

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $query = User::query()
            ->excludingSharedAccounts()
            ->whereDate('onboarded_at', today()->subDay())
            ->whereNotNull('paywall_seen_at')
            ->whereDoesntHave('mailLogs', function ($query): void {
                $query->where('email_type', DripEmailType::PaywallFollowUp);
            });

        $queued = 0;

        $query->chunkById(100, function ($users) use (&$queued): void {
            foreach ($users as $user) {
                if ($user->hasProPlan()) {
                    continue;
                }

                SendPaywallFollowUpEmailJob::dispatch($user);
                $queued++;
            }
        });

        $this->info("Queued {$queued} paywall follow-up email(s).");

        return self::SUCCESS;
    }
}
