<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\NotifiesBankUsers;
use App\Enums\BankingConnectionStatus;
use App\Enums\DripEmailType;
use App\Mail\BankConnectFailedEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

/**
 * Run by hand for a bank that cannot be connected at all: it tells the people
 * who tried and failed that the bank's authorization is broken, that it was not
 * their fault, and that we will write again when it works.
 *
 * This is the other half of {@see NotifyBankOutageCommand}. That one is for a
 * working connection that went quiet; this one is for a connection that never
 * existed, because the bank never let it be created.
 */
class NotifyBankConnectFailureCommand extends Command implements Isolatable
{
    use NotifiesBankUsers;

    /**
     * How many distinct people must have hit the bank before the copy's claim
     * that it fails for everyone can be defended. One user cancelling twice at
     * their bank leaves the same trace as a broken connector.
     */
    private const MIN_AFFECTED_USERS = 2;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:notify-connect-failure
                            {aspsp : The bank name as stored on the attempt, e.g. "Banco Mediolanum"}
                            {--country= : ISO country code, required when attempts span several (e.g. ES)}
                            {--since= : Only notify users who tried on or after this date (e.g. 2026-06-01)}
                            {--dry-run : List the recipients without sending anything}
                            {--force : Skip the confirmation prompt}
                            {--resend : Notify users who already got this bank\'s notice}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email the users whose attempts to connect a bank never got past its authorization';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $aspsp = (string) $this->argument('aspsp');
        $country = $this->countryOption();

        $banks = $this->affectedBanks($this->recentFailures($this->matchesBank($aspsp, $country)));

        if ($banks->isEmpty()) {
            return $this->reportNoFailures($aspsp, $country);
        }

        $country = $this->resolveCountry($banks, $aspsp, $country);

        if ($country === null) {
            return self::FAILURE;
        }

        $bankName = (string) $banks->first()->aspsp_name;
        $displayName = "{$bankName} ({$country})";
        $matchesBank = $this->matchesBank($bankName, $country);

        if (! $this->isBankProvenBroken($matchesBank, $displayName)) {
            return self::FAILURE;
        }

        $identifier = $this->bankIdentifier($bankName, $country);
        $users = $this->recipients($this->recentFailures($matchesBank), DripEmailType::BankConnectFailed, $identifier);

        if ($users->isEmpty()) {
            $this->info("Nobody to notify about {$displayName}: everyone who tried was already notified, or has since deleted their account. Use --resend to send it again.");

            return self::SUCCESS;
        }

        $this->renderRecipients($users, $this->lastAttempts($matchesBank), $displayName);

        if (! $this->shouldSend("Tell {$users->count()} user(s) that {$displayName} cannot be connected?")) {
            return self::SUCCESS;
        }

        $this->sendAndLog(
            $users,
            DripEmailType::BankConnectFailed,
            $identifier,
            fn (User $user) => new BankConnectFailedEmail($user, $bankName),
        );

        return self::SUCCESS;
    }

    /**
     * Attempts the bank itself sent back as an error.
     *
     * A soft-deleted `pending` row is the fingerprint: AuthorizationController::
     * handleAuthorizationError() is the only path that deletes a connection while
     * it is still pending, and a manual disconnect sets Revoked before deleting.
     * A `pending` row that is *not* deleted means the user simply never came back
     * from the bank, which is not something to apologise for.
     */
    private function failedAuthorizations(Closure $matchesBank): Closure
    {
        return fn (Builder $query) => $query
            ->tap($matchesBank)
            // Only the soft-deleted rows: a rejected callback deletes the
            // connection, so every attempt worth reporting is trashed. The column
            // is qualified because this also runs as a subquery against `users`,
            // which has a `deleted_at` of its own.
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull('banking_connections.deleted_at')
            ->where('status', BankingConnectionStatus::Pending);
    }

    /**
     * The same attempts, narrowed to the `--since` window. Who gets an email is a
     * separate question from whether the bank is broken, so the evidence check
     * below deliberately ignores the window and reads the whole history.
     */
    private function recentFailures(Closure $matchesBank): Closure
    {
        return fn (Builder $query) => $query
            ->tap($this->failedAuthorizations($matchesBank))
            ->when(
                $this->option('since'),
                fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse((string) $this->option('since'))),
            );
    }

    /**
     * Whether the data proves the bank cannot be connected, rather than one person
     * having had a bad time with it.
     *
     * Two things must hold. The bank must never have produced a session for
     * anybody: the callback error code is not stored — only Sentry has it — so a
     * single failed attempt cannot be told apart from someone cancelling at the
     * bank, and for a bank that does connect this notice would call that
     * cancellation a bank failure. And more than one person must have hit it,
     * because the email says it is failing for everyone who tries.
     */
    private function isBankProvenBroken(Closure $matchesBank, string $displayName): bool
    {
        $sessions = BankingConnection::withTrashed()
            ->tap($matchesBank)
            ->whereNotNull('session_id')
            ->count();

        if ($sessions > 0) {
            $this->error("{$displayName} has completed {$sessions} authorization(s), so it does connect.");
            $this->line('This notice is only for banks that have never once connected: anywhere else a failed callback can just be someone cancelling at the bank, and telling them it was the bank would be wrong. Read the authorization errors in Sentry first.');

            return false;
        }

        $attempts = BankingConnection::query()->tap($this->failedAuthorizations($matchesBank))->count();
        $affected = BankingConnection::query()->tap($this->failedAuthorizations($matchesBank))->distinct()->count('user_id');

        if ($affected < self::MIN_AFFECTED_USERS) {
            $this->error("Only {$affected} user(s) have ever hit a failed authorization at {$displayName}, over {$attempts} attempt(s).");
            $this->line('That is too little to claim the bank is broken: one person\'s attempts look the same whether the connector failed or they cancelled at the bank. Read the authorization errors in Sentry and write to them by hand instead.');

            return false;
        }

        $this->info("{$displayName} has never connected: {$attempts} failed attempt(s) from {$affected} user(s), and not one session.");

        return true;
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<string, string>  $lastAttempts
     */
    private function renderRecipients(Collection $users, Collection $lastAttempts, string $displayName): void
    {
        $this->table(['Email', 'Failed attempts', 'Last attempt'], $users->map(fn (User $user) => [
            $user->email,
            (int) $user->getAttribute(self::MATCHED_CONNECTIONS),
            (string) $lastAttempts->get($user->id),
        ])->all());

        $this->info("{$users->count()} user(s) tried and failed to connect {$displayName}.");
    }

    /**
     * When each user last hit the bank's broken authorization, so the operator can
     * see who is still waiting and who gave up months ago.
     *
     * @return Collection<string, string>
     */
    private function lastAttempts(Closure $matchesBank): Collection
    {
        return BankingConnection::query()
            ->tap($this->recentFailures($matchesBank))
            ->selectRaw('user_id, MAX(created_at) as last_attempt')
            ->groupBy('user_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $row->user_id => Carbon::parse($row->last_attempt)->toDateTimeString(),
            ]);
    }

    /**
     * Nothing to report for this bank: either no attempt of its own ever failed,
     * or the name does not match anything we have seen.
     */
    private function reportNoFailures(string $aspsp, ?string $country): int
    {
        $displayName = $aspsp.($country ? " ({$country})" : '');
        $attempts = BankingConnection::withTrashed()->tap($this->matchesBank($aspsp, $country))->count();

        if ($attempts > 0) {
            $this->info("None of the {$attempts} connection attempt(s) to {$displayName} came back as a bank error, so there is nobody to apologise to.");

            return self::SUCCESS;
        }

        $this->reportUnknownBank($aspsp, $country);

        return self::SUCCESS;
    }
}
