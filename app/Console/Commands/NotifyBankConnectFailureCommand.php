<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\NotifiesBankUsers;
use App\Enums\BankingConnectionStatus;
use App\Enums\DripEmailType;
use App\Mail\BankConnectFailedEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Closure;
use Illuminate\Console\Command;
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
class NotifyBankConnectFailureCommand extends Command
{
    use NotifiesBankUsers;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:notify-connect-failure
                            {aspsp : The bank name as stored on the attempt, e.g. "Banco Mediolanum"}
                            {--country= : ISO country code, required when attempts span several (e.g. ES)}
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

        $banks = BankingConnection::query()
            ->tap($this->failedCallbacks($this->matchesBank($aspsp, $country)))
            ->select('aspsp_name', 'aspsp_country')
            ->distinct()
            ->orderBy('aspsp_name')
            ->get();

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

        if (! $this->isBankUnusable($matchesBank, $displayName)) {
            return self::FAILURE;
        }

        $identifier = $this->bankIdentifier($bankName, $country);
        $users = $this->recipients($this->failedCallbacks($matchesBank), DripEmailType::BankConnectFailed, $identifier);

        if ($users->isEmpty()) {
            $this->info("Nobody to notify about {$displayName}: everyone who tried was already notified or cannot receive email. Use --resend to send it again.");

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
    private function failedCallbacks(Closure $matchesBank): Closure
    {
        return fn (Builder $query) => $query
            ->tap($matchesBank)
            // Only the soft-deleted rows: a rejected callback deletes the
            // connection, so every attempt worth reporting is trashed.
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull('deleted_at')
            ->where('status', BankingConnectionStatus::Pending);
    }

    /**
     * Whether this bank has never once let a connection be created.
     *
     * The callback error code is not stored — only Sentry has it — so a single
     * failed attempt cannot be told apart from a user cancelling at the bank.
     * What the database can prove is that a bank has never produced a session
     * for anybody, and for such a bank "it was not your fault" is safe to say.
     * For a bank that does connect, this notice would call some users' own
     * cancellation a bank failure, so the command refuses to run.
     */
    private function isBankUnusable(Closure $matchesBank, string $displayName): bool
    {
        $sessions = BankingConnection::withTrashed()
            ->tap($matchesBank)
            ->whereNotNull('session_id')
            ->count();

        if ($sessions === 0) {
            return true;
        }

        $this->error("{$displayName} has completed {$sessions} authorization(s), so it does connect.");
        $this->line('This notice is only for banks that have never once connected: elsewhere a failed callback can just be someone cancelling at the bank, and telling them it was the bank would be wrong. Check the authorization errors in Sentry first.');

        return false;
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<string, mixed>  $lastAttempts
     */
    private function renderRecipients(Collection $users, Collection $lastAttempts, string $displayName): void
    {
        $this->table(['Email', 'Failed attempts', 'Last attempt'], $users->map(fn (User $user) => [
            $user->email,
            (int) $user->getAttribute('matched_connections_count'),
            (string) $lastAttempts->get($user->id, 'unknown'),
        ])->all());

        $this->info("{$users->count()} user(s) tried and failed to connect {$displayName}.");
    }

    /**
     * When each user last hit the bank's broken authorization, so the operator can
     * see who is still waiting and who gave up months ago.
     *
     * @return Collection<string, mixed>
     */
    private function lastAttempts(Closure $matchesBank): Collection
    {
        return BankingConnection::query()
            ->tap($this->failedCallbacks($matchesBank))
            ->selectRaw('user_id, MAX(created_at) as last_attempt')
            ->groupBy('user_id')
            ->get()
            ->pluck('last_attempt', 'user_id');
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
