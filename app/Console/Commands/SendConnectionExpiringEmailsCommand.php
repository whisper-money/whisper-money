<?php

namespace App\Console\Commands;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Jobs\Drip\SendConnectionExpiringEmailJob;
use App\Models\BankingConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SendConnectionExpiringEmailsCommand extends Command
{
    protected $signature = 'email:connection-expiring';

    protected $description = 'Warn users whose bank consent runs out within the week so they can renew it before syncing breaks';

    public function handle(): int
    {
        if (! config('mail.drip_emails_enabled')) {
            $this->info('Drip emails are disabled. Nothing to do.');

            return self::SUCCESS;
        }

        $queued = 0;

        // The whole window rather than the one day exactly a week out: a missed
        // run must not cost the user their warning, and a bank that grants a
        // consent shorter than the window would never match a single day.
        // Re-dispatching for the same connection is free, because the job keys
        // its mail log on the consent window and drops the repeats.
        //
        // Already-expired consents are deliberately absent: SyncBankingConnectionJob
        // flips those to Expired and emails them itself.
        BankingConnection::query()
            ->where('provider', BankingProvider::EnableBanking)
            ->where('status', BankingConnectionStatus::Active)
            ->where('valid_until', '>', now())
            ->where('valid_until', '<', now()->addDays(BankingConnection::EXPIRY_WARNING_DAYS))
            ->whereHas('user', fn (Builder $query) => $query->excludingSharedAccounts())
            ->with('user')
            ->chunkById(100, function (Collection $connections) use (&$queued): void {
                foreach ($connections as $connection) {
                    SendConnectionExpiringEmailJob::dispatch($connection->user, $connection);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} connection-expiring email(s).");

        return self::SUCCESS;
    }
}
