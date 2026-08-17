<?php

namespace App\Console\Commands;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\DripEmailType;
use App\Jobs\SyncBankingConnectionJob;
use App\Mail\BankOutageEmail;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Run by hand once a bank-side outage is confirmed: it tells everyone connected
 * to that bank that their transactions and balances are stuck because the bank
 * stopped answering, and that we are on it.
 *
 * Connections are matched on `aspsp_name` + `aspsp_country`, which is also how
 * Enable Banking identifies an ASPSP: `banking_connections` has no bank_id, and
 * the `banks` table is per-user, so a bank UUID means nothing outside the one
 * account it belongs to.
 */
class NotifyBankOutageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:notify-outage
                            {aspsp : The bank name as stored on the connection, e.g. "Openbank"}
                            {--country= : ISO country code, required when the bank spans several (e.g. ES)}
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
        $country = $this->option('country') ? Str::upper((string) $this->option('country')) : null;

        $matchesBank = $this->matchesBank($aspsp, $country);
        $matchesOutage = $this->matchesOutage($matchesBank);

        $banks = BankingConnection::query()->tap($matchesOutage)
            ->select('aspsp_name', 'aspsp_country')
            ->distinct()
            ->orderBy('aspsp_name')
            ->get();

        if ($banks->isEmpty()) {
            return $this->reportNoMatches($matchesBank, $aspsp, $country);
        }

        $countries = $banks->pluck('aspsp_country')->unique();

        if ($country === null && $countries->count() > 1) {
            $this->error("{$aspsp} has affected connections in ".$countries->sort()->implode(', ').'.');
            $this->line('An Enable Banking outage is per bank and country, so re-run with --country=XX.');

            return self::FAILURE;
        }

        // One country is in play from here on: adopt it, so both the console
        // output and the ledger identifier name the ASPSP unambiguously.
        $bankName = (string) $banks->first()->aspsp_name;
        $country ??= (string) $countries->first();
        $identifier = Str::slug("{$bankName}-{$country}");

        $users = $this->recipients($matchesOutage, $identifier);

        if ($users->isEmpty()) {
            $this->info("Nobody to notify about the {$bankName} ({$country}) outage: everyone affected was already notified or cannot receive email. Use --resend to send it again.");

            return self::SUCCESS;
        }

        $this->reportScope($users, $matchesBank, $matchesOutage, "{$bankName} ({$country})");

        if ($this->option('dry-run')) {
            $this->info('[dry-run] No emails sent.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Send the {$bankName} ({$country}) outage notice to {$users->count()} user(s)?", false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $this->send($users, $bankName, $identifier);

        return self::SUCCESS;
    }

    /**
     * Every Enable Banking connection to the bank the operator named.
     */
    private function matchesBank(string $aspsp, ?string $country): Closure
    {
        return fn (Builder $query) => $query
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', $aspsp)
            ->when($country, fn (Builder $query) => $query->where('aspsp_country', $country));
    }

    /**
     * Of those, the ones an outage at the bank actually explains: the connections
     * the scheduler will keep retrying, mirroring SyncAllBankingConnectionsJob.
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
     * The users to email: one row each, however many connections they have to
     * the bank, minus anyone already notified about this outage.
     *
     * @return Collection<int, User>
     */
    private function recipients(Closure $matchesOutage, string $identifier): Collection
    {
        return User::query()
            ->whereHas('bankingConnections', $matchesOutage)
            ->withCount(['bankingConnections as affected_connections_count' => $matchesOutage])
            ->unless($this->option('resend'), fn (Builder $query) => $query->whereDoesntHave(
                'mailLogs',
                fn (Builder $query) => $query
                    ->where('email_type', DripEmailType::BankOutage)
                    ->where('email_identifier', $identifier),
            ))
            ->orderBy('email')
            ->get()
            ->filter->canReceiveEmails();
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
            (int) $user->getAttribute('affected_connections_count'),
        ])->all());

        $this->info("{$users->count()} user(s) to notify about the {$displayName} outage.");

        $excluded = BankingConnection::query()->tap($matchesBank)->count()
            - BankingConnection::query()->tap($matchesOutage)->count();

        if ($excluded > 0) {
            $this->warn("Skipping {$excluded} other connection(s) to {$displayName}: expired, revoked or past the retry cap. Those need a reconnect, not this notice.");
        }
    }

    /**
     * Explain a run that resolved nobody, so a mistyped or renamed bank does not
     * read as "no one is affected".
     */
    private function reportNoMatches(Closure $matchesBank, string $aspsp, ?string $country): int
    {
        $displayName = $aspsp.($country ? " ({$country})" : '');
        $existing = BankingConnection::query()->tap($matchesBank)->count();

        if ($existing > 0) {
            $this->warn("{$existing} connection(s) to {$displayName} exist, but none is waiting on the bank: expired, revoked or past the retry cap. Those need a reconnect, not this notice.");

            return self::SUCCESS;
        }

        $this->info("No Enable Banking connection to {$displayName}.");

        $similar = BankingConnection::query()
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', 'like', '%'.$aspsp.'%')
            ->distinct()
            ->orderBy('aspsp_name')
            ->pluck('aspsp_name');

        if ($similar->isNotEmpty()) {
            $this->line('Did you mean: '.$similar->implode(', ').'?');
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function send(Collection $users, string $bankName, string $identifier): void
    {
        foreach ($users as $user) {
            Mail::to($user)->send(new BankOutageEmail($user, $bankName));

            // Logged when queued rather than when delivered: the ledger exists so
            // a second run during the same outage skips whoever already got it.
            UserMailLog::updateOrCreate([
                'user_id' => $user->id,
                'email_type' => DripEmailType::BankOutage,
                'email_identifier' => $identifier,
            ], ['sent_at' => now()]);
        }

        $this->info("Queued {$users->count()} outage notice(s) to the 'emails' queue.");
    }
}
