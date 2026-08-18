<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\NotifiesBankUsers;
use App\Enums\BankingConnectionStatus;
use App\Enums\DripEmailType;
use App\Jobs\SyncBankingConnectionJob;
use App\Mail\BankOutageEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Run by hand once a bank-side outage is confirmed: it tells everyone connected
 * to that bank that their transactions and balances are stuck because the bank
 * stopped answering, and that we are on it.
 */
class NotifyBankOutageCommand extends Command implements Isolatable
{
    use NotifiesBankUsers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:notify-outage
                            {aspsp : The bank name as stored on the connection, e.g. "Openbank"}
                            {--country= : ISO country code, required when the bank is affected in several (e.g. ES)}
                            {--dry-run : List the recipients without sending anything}
                            {--force : Skip the confirmation prompt}
                            {--resend : Notify users who already got this bank\'s outage notice}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email the users whose Enable Banking connections are stuck on a bank-side outage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $aspsp = (string) $this->argument('aspsp');
        $country = $this->countryOption();

        $banks = $this->affectedBanks($this->matchesOutage($this->matchesBank($aspsp, $country)));

        if ($banks->isEmpty()) {
            return $this->reportNoOutage($aspsp, $country);
        }

        $country = $this->resolveCountry($banks, $aspsp, $country);

        if ($country === null) {
            return self::FAILURE;
        }

        // Both the bank name and the country now come from the data, so the
        // console output, the email and the ledger key all agree.
        $bankName = (string) $banks->first()->aspsp_name;
        $displayName = "{$bankName} ({$country})";
        $matchesBank = $this->matchesBank($bankName, $country);
        $matchesOutage = $this->matchesOutage($matchesBank);

        $users = $this->recipients($matchesOutage, DripEmailType::BankOutage, $this->bankIdentifier($bankName, $country));

        if ($users->isEmpty()) {
            $this->info("Nobody to notify about the {$displayName} outage: everyone affected was already notified, or has since deleted their account. Use --resend to send it again.");

            return self::SUCCESS;
        }

        $this->reportScope($users, $matchesBank, $matchesOutage, $displayName);

        if (! $this->shouldSend("Send the {$displayName} outage notice to {$users->count()} user(s)?")) {
            return self::SUCCESS;
        }

        $this->sendAndLog(
            $users,
            DripEmailType::BankOutage,
            $this->bankIdentifier($bankName, $country),
            fn (User $user) => new BankOutageEmail($user, $bankName),
        );

        return self::SUCCESS;
    }

    /**
     * The connections an outage at the bank actually explains: the ones the
     * scheduler will keep retrying, mirroring SyncAllBankingConnectionsJob.
     *
     * This is what makes the email honest. An expired, revoked or retry-capped
     * connection is stuck for its own reason and its owner does have to
     * reconnect — the exact opposite of what this notice tells them.
     */
    private function matchesOutage(Closure $matchesBank): Closure
    {
        return fn (Builder $query) => $query
            ->tap($matchesBank)
            ->where(fn (Builder $query) => $query
                ->where('status', BankingConnectionStatus::Active)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', BankingConnectionStatus::Error)
                    ->where('consecutive_sync_failures', '<', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES)))
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>', now()));
    }

    /**
     * Show who is about to be emailed, and flag the connections to the same bank
     * that are deliberately left out because this notice would be wrong for them.
     *
     * @param  Collection<int, User>  $users
     */
    private function reportScope(Collection $users, Closure $matchesBank, Closure $matchesOutage, string $displayName): void
    {
        $this->table(['Email', 'Connections'], $users->map(fn (User $user) => [
            $user->email,
            (int) $user->getAttribute(self::MATCHED_CONNECTIONS),
        ])->all());

        $this->info("{$users->count()} user(s) to notify about the {$displayName} outage.");

        $excluded = BankingConnection::query()->tap($matchesBank)->count()
            - BankingConnection::query()->tap($matchesOutage)->count();

        if ($excluded > 0) {
            $this->warn("Skipping {$excluded} other connection(s) to {$displayName}: expired, revoked or past the retry cap. Those need a reconnect, not this notice.");
        }
    }

    /**
     * Nothing is waiting on this bank: either it has no live connection at all,
     * or the ones it has are broken for reasons an outage does not explain.
     */
    private function reportNoOutage(string $aspsp, ?string $country): int
    {
        $displayName = $aspsp.($country ? " ({$country})" : '');
        $existing = BankingConnection::query()->tap($this->matchesBank($aspsp, $country))->count();

        if ($existing > 0) {
            $this->warn("{$existing} connection(s) to {$displayName} exist, but none is waiting on the bank: expired, revoked or past the retry cap. Those need a reconnect, not this notice.");

            return self::SUCCESS;
        }

        $this->reportUnknownBank($aspsp, $country);

        return self::SUCCESS;
    }
}
