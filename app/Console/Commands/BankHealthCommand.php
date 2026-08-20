<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\QueriesBankFailures;
use App\Enums\BankingConnectionStatus;
use App\Mail\BankHealthAlertEmail;
use App\Models\BankingConnection;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * How every Enable Banking bank is doing, in one table, and an email for the
 * ones that are broken for everybody.
 *
 * There are two ways a bank fails and the report covers both, because there is
 * a command to notify the users for each: an authorization that never completes
 * ({@see NotifyBankConnectFailureCommand}) and connections that used to work and
 * stopped ({@see NotifyBankOutageCommand}). Which bank is in which state is
 * decided by those commands' own predicates, shared through
 * {@see QueriesBankFailures}, so the table cannot claim something different from
 * the notice it tells the operator to send.
 *
 * The metric is internal: nothing here is shown to a user, and the email goes to
 * REPORT_RECIPIENTS.
 */
class BankHealthCommand extends Command
{
    use QueriesBankFailures;

    /**
     * A bank nobody is currently connected to, so the sync half of the report has
     * nothing to say about it.
     */
    private const NO_LIVE_CONNECTIONS = [
        'live' => 0,
        'live_users' => 0,
        'rate_limited' => 0,
        'syncing' => 0,
        'failing' => 0,
        'failing_users' => 0,
        'newest_sync' => null,
        'newest_failing_sync' => null,
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:health
                            {--email : Also email the owners about the banks that are broken for everyone}
                            {--repeat-after=7 : Days before the same bank can be alerted about again}
                            {--stale-hours=48 : Hours without a successful sync before a live connection counts as failing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Report how every Enable Banking bank is doing, and alert on the ones that are broken for everyone';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $banks = $this->collectBanks();

        if ($banks->isEmpty()) {
            $this->info('No Enable Banking connection on record.');

            return self::SUCCESS;
        }

        $this->renderReport($banks);

        if (! $this->option('email')) {
            return self::SUCCESS;
        }

        return $this->sendAlert($banks);
    }

    /**
     * One row per bank, worst first, with both funnels already summarised.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function collectBanks(): Collection
    {
        $failures = $this->authorizationFailures();
        $sync = $this->syncHealth();

        return $this->attempts()
            ->map(fn (array $bank, string $key) => $this->summarise([
                ...$bank,
                ...($failures->get($key) ?? ['failed_authorizations' => 0, 'affected_users' => 0]),
                ...($sync[$key] ?? self::NO_LIVE_CONNECTIONS),
            ]))
            ->sortBy([['severity', 'desc'], ['failing', 'desc'], ['users', 'desc'], ['name', 'asc']])
            ->values();
    }

    /**
     * Every bank we have ever tried to connect, with its authorization funnel.
     *
     * Soft-deleted rows are counted on purpose: a bank whose every attempt failed
     * has nothing but soft-deleted rows, and leaving them out would report it as a
     * bank nobody ever tried.
     *
     * @return Collection<string, array{name: string, country: string, attempts: int, users: int, sessions: int}>
     */
    private function attempts(): Collection
    {
        return BankingConnection::withTrashed()
            ->tap($this->matchesEnableBanking())
            ->groupBy('aspsp_name', 'aspsp_country')
            // COUNT over a nullable column skips the nulls, so this counts the
            // authorizations that actually completed.
            ->selectRaw('aspsp_name, aspsp_country, COUNT(*) as attempts, COUNT(DISTINCT user_id) as users, COUNT(session_id) as sessions')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => $this->bankIdentifier($row->aspsp_name, $row->aspsp_country))
            ->map(fn (object $row): array => [
                'name' => (string) $row->aspsp_name,
                'country' => (string) $row->aspsp_country,
                'attempts' => (int) $row->attempts,
                'users' => (int) $row->users,
                'sessions' => (int) $row->sessions,
            ]);
    }

    /**
     * The attempts each bank's own authorization rejected.
     *
     * @return Collection<string, array{failed_authorizations: int, affected_users: int}>
     */
    private function authorizationFailures(): Collection
    {
        return BankingConnection::query()
            ->tap($this->failedAuthorizations($this->matchesEnableBanking()))
            ->groupBy('aspsp_name', 'aspsp_country')
            ->selectRaw('aspsp_name, aspsp_country, COUNT(*) as failed_authorizations, COUNT(DISTINCT user_id) as affected_users')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => $this->bankIdentifier($row->aspsp_name, $row->aspsp_country))
            ->map(fn (object $row): array => [
                'failed_authorizations' => (int) $row->failed_authorizations,
                'affected_users' => (int) $row->affected_users,
            ]);
    }

    /**
     * How the connections the scheduler is still retrying are actually doing.
     *
     * The population is small enough (a few hundred rows) to summarise in PHP,
     * which keeps the two states below readable instead of hidden in conditional
     * SQL aggregates.
     *
     * @return array<array-key, array{live: int, live_users: int, rate_limited: int, syncing: int, failing: int, failing_users: int, newest_sync: CarbonInterface|null, newest_failing_sync: CarbonInterface|null}>
     */
    private function syncHealth(): array
    {
        return BankingConnection::query()
            ->tap($this->matchesOutage($this->matchesEnableBanking()))
            ->get(['aspsp_name', 'aspsp_country', 'user_id', 'status', 'last_synced_at', 'rate_limited_until'])
            ->groupBy(fn (BankingConnection $connection): string => $this->bankIdentifier(
                (string) $connection->aspsp_name,
                (string) $connection->aspsp_country,
            ))
            ->map(fn (Collection $connections): array => $this->summariseSync($connections))
            ->all();
    }

    /**
     * @param  Collection<int, BankingConnection>  $connections
     * @return array{live: int, live_users: int, rate_limited: int, syncing: int, failing: int, failing_users: int, newest_sync: CarbonInterface|null, newest_failing_sync: CarbonInterface|null}
     */
    private function summariseSync(Collection $connections): array
    {
        [$backingOff, $syncing] = $connections->partition(fn (BankingConnection $connection): bool => $this->isBackingOff($connection));
        $failing = $syncing->filter(fn (BankingConnection $connection): bool => $this->isFailing($connection));

        return [
            'live' => $connections->count(),
            'live_users' => $connections->unique('user_id')->count(),
            'rate_limited' => $backingOff->count(),
            'syncing' => $syncing->count(),
            'failing' => $failing->count(),
            'failing_users' => $failing->unique('user_id')->count(),
            'newest_sync' => $connections->max('last_synced_at'),
            // Separate from the column above on purpose: a throttled connection
            // syncing an hour ago says nothing about the ones that are down, and
            // quoting it would make the alert read better than the truth.
            'newest_failing_sync' => $failing->max('last_synced_at'),
        ];
    }

    /**
     * Whether the provider is throttling this connection rather than failing it.
     *
     * This is the one state the report has to hold apart by hand. Trade Republic
     * accounts for most of our rate limits and its 429 lands on the balances call,
     * *after* the transactions import, so these connections import data perfectly
     * and back off daily on their balances alone. Counted as failing they would put
     * the bank in this report's alert with an outage notice its users must not get.
     *
     * They used to look permanently stale too, because the rethrown 429 discarded
     * the whole run and never wrote `last_synced_at`. Now that such a run is kept,
     * a backoff is written on *successful* runs as well, so this partition also
     * holds connections that are throttled and completely up to date: `syncing`
     * under-counts them and `rate_limited` counts them every day. Folding them
     * back would mean one healthy connection could mask a bank whose others have
     * all stopped, which is the masking this partition exists to prevent - so the
     * count stays as it is and the report prints both numbers.
     *
     * The window, rather than `rate_limited_until` simply being in the future,
     * covers the gap between a backoff elapsing and the next scheduled sync
     * getting round to retrying it.
     */
    private function isBackingOff(BankingConnection $connection): bool
    {
        return $connection->rate_limited_until !== null
            && $connection->rate_limited_until->greaterThan(now()->subHours($this->staleHours()));
    }

    /**
     * Whether a connection the scheduler is still retrying has stopped producing
     * data.
     *
     * `consecutive_sync_failures` is deliberately not the signal: it is 0 on every
     * live connection in production, because it is only written on the path that
     * also caps the retries — and passing the cap takes the connection out of this
     * population altogether. What is left is the status the last run wrote and the
     * sync clock. The scheduler runs every six hours, so the default 48 hours is
     * eight missed attempts in a row: long past transient.
     */
    private function isFailing(BankingConnection $connection): bool
    {
        return $connection->status === BankingConnectionStatus::Error
            || $connection->last_synced_at === null
            || $connection->last_synced_at->lessThan(now()->subHours($this->staleHours()));
    }

    /**
     * The verdict on one bank: which failure mode it is in, if any, how to notify
     * its users, and how badly it sorts.
     *
     * @param  array<string, mixed>  $bank
     * @return array<string, mixed>
     */
    private function summarise(array $bank): array
    {
        $displayName = "{$bank['name']} ({$bank['country']})";

        return [
            ...$bank,
            'key' => $this->bankIdentifier((string) $bank['name'], (string) $bank['country']),
            'display_name' => $displayName,
            'state' => $this->state($bank),
            'severity' => $this->severity($bank),
            'notify_command' => $this->notifyCommand($bank),
            'reason' => $this->reason($bank),
        ];
    }

    /**
     * Whether the bank rejected people and has never once let anybody in. The
     * callback error code is not stored, so a rejection on a bank that does
     * connect is just as likely to be someone cancelling at their bank.
     *
     * @param  array<string, mixed>  $bank
     */
    private function neverConnected(array $bank): bool
    {
        return $bank['sessions'] === 0 && $bank['failed_authorizations'] > 0;
    }

    /**
     * Whether that is provably the bank's fault rather than one person's bad
     * afternoon, which is what `banking:notify-connect-failure` apologises for.
     *
     * @param  array<string, mixed>  $bank
     */
    private function neverAuthorizes(array $bank): bool
    {
        return $this->neverConnected($bank)
            && $bank['affected_users'] >= self::MIN_AFFECTED_USERS;
    }

    /**
     * Whether every connection the bank could still be syncing has stopped, which
     * is what `banking:notify-outage` announces. Throttled connections are out of
     * both halves of the fraction: they are not failing, and they are not proof
     * the bank works either.
     *
     * @param  array<string, mixed>  $bank
     */
    private function isFullyDown(array $bank): bool
    {
        return $bank['failing'] > 0
            && $bank['failing'] === $bank['syncing']
            && $bank['failing_users'] >= self::MIN_AFFECTED_USERS;
    }

    /**
     * The verdict in one cell. `BROKEN` is the only state that gets an email, so
     * everything one bar short of it says which bar it missed rather than reading
     * as a milder problem: a bank failing all of its connections for a single user
     * is not degraded, it is unproven.
     *
     * @param  array<string, mixed>  $bank
     */
    private function state(array $bank): string
    {
        if ($this->neverAuthorizes($bank)) {
            return 'BROKEN: never connects';
        }

        // A bank that never completed an authorization has no live connection to
        // say anything about, so the sync half below would only report silence.
        if ($this->neverConnected($bank)) {
            return "unproven: never connects, {$bank['affected_users']} user(s)";
        }

        return $this->syncState($bank);
    }

    /**
     * @param  array<string, mixed>  $bank
     */
    private function syncState(array $bank): string
    {
        return match (true) {
            $this->isFullyDown($bank) => "BROKEN: {$bank['failing']}/{$bank['syncing']} not syncing",
            $bank['failing'] > 0 && $bank['failing'] === $bank['syncing'] => "unproven: {$bank['failing']}/{$bank['syncing']} not syncing, {$bank['failing_users']} user(s)",
            $bank['failing'] > 0 => "degraded: {$bank['failing']}/{$bank['syncing']} not syncing",
            $bank['live'] > 0 && $bank['rate_limited'] === $bank['live'] => 'throttled',
            $bank['live'] > 0 => 'ok',
            default => 'nobody connected',
        };
    }

    /**
     * Sort weight, so a table too long to read still opens on the banks that need
     * a decision.
     *
     * @param  array<string, mixed>  $bank
     */
    private function severity(array $bank): int
    {
        return match (true) {
            $this->neverAuthorizes($bank), $this->isFullyDown($bank) => 3,
            $this->neverConnected($bank), $bank['failing'] > 0 => 2,
            $bank['rate_limited'] > 0 => 1,
            default => 0,
        };
    }

    /**
     * The command that tells this bank's users about it, ready to paste, or null
     * when the bank is not broken enough to write to anybody.
     *
     * @param  array<string, mixed>  $bank
     */
    private function notifyCommand(array $bank): ?string
    {
        $command = match (true) {
            $this->neverAuthorizes($bank) => 'banking:notify-connect-failure',
            $this->isFullyDown($bank) => 'banking:notify-outage',
            default => null,
        };

        if ($command === null) {
            return null;
        }

        // Double quotes so the operator can read the bank name, with the four
        // characters a shell still expands inside them escaped.
        $name = addcslashes((string) $bank['name'], '"\\$`');

        return "php artisan {$command} \"{$name}\" --country={$bank['country']}";
    }

    /**
     * Why this bank is being alerted about, in one sentence, so the email stands
     * on its own.
     *
     * @param  array<string, mixed>  $bank
     */
    private function reason(array $bank): ?string
    {
        if ($this->neverAuthorizes($bank)) {
            return "Never connected once: {$bank['failed_authorizations']} authorization(s) rejected by the bank, "
                ."from {$bank['affected_users']} user(s), and not a single session in {$bank['attempts']} attempt(s).";
        }

        if ($this->isFullyDown($bank)) {
            $lastSync = $bank['newest_failing_sync'] instanceof CarbonInterface
                ? 'the most recent of them synced '.$bank['newest_failing_sync']->diffForHumans()
                : 'not one of them has ever synced';

            return "All {$bank['failing']} of its {$bank['syncing']} connection(s) that should be syncing have stopped, across "
                ."{$bank['failing_users']} user(s); {$lastSync}."
                .($bank['rate_limited'] > 0 ? " A further {$bank['rate_limited']} are rate limited and left out of that count." : '');
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $banks
     */
    private function renderReport(Collection $banks): void
    {
        $this->table(
            ['Bank', 'Country', 'Attempts', 'Failed auth', 'Users', 'Connected', 'Live', 'Not syncing', 'Throttled', 'Newest sync', 'State'],
            $banks->map(fn (array $bank): array => [
                $bank['name'],
                $bank['country'],
                $bank['attempts'],
                $bank['failed_authorizations'],
                $bank['users'],
                $bank['sessions'] > 0 ? 'yes' : 'no',
                $bank['live'],
                $bank['syncing'] > 0 ? "{$bank['failing']}/{$bank['syncing']}" : '-',
                $bank['rate_limited'] ?: '-',
                $bank['newest_sync'] instanceof CarbonInterface ? $bank['newest_sync']->diffForHumans() : 'never',
                $bank['state'],
            ])->all(),
        );

        $broken = $banks->whereNotNull('notify_command');

        $this->info("{$banks->count()} bank(s) on record, {$broken->count()} broken for everyone who uses them.");
    }

    /**
     * Email the owners about the banks that are broken for everyone, skipping any
     * already alerted about inside the window.
     *
     * @param  Collection<int, array<string, mixed>>  $banks
     */
    private function sendAlert(Collection $banks): int
    {
        /** @var array<int, string> $recipients */
        $recipients = config('mail.report_recipients');

        if ($recipients === []) {
            $this->error('REPORT_RECIPIENTS is not configured. Skipping the bank health alert.');

            return self::FAILURE;
        }

        $broken = $banks->whereNotNull('notify_command');

        if ($broken->isEmpty()) {
            $this->info('No bank is broken for everyone. Nothing to alert about.');

            return self::SUCCESS;
        }

        $fresh = $broken->reject(fn (array $bank): bool => Cache::has($this->alertKey($bank)))->values();

        if ($fresh->isEmpty()) {
            $this->info("All {$broken->count()} broken bank(s) were already alerted about in the last {$this->repeatAfter()} day(s).");

            return self::SUCCESS;
        }

        Mail::to($recipients)->send(new BankHealthAlertEmail(
            $fresh->all(),
            $this->repeatAfter(),
            $this->looksLikeProviderOutage($banks, $broken),
        ));

        $this->suppressUntilWindowElapses($fresh);

        $this->warn("Alerted about {$fresh->count()} bank(s): ".$fresh->pluck('display_name')->implode(', ').'.');

        return self::SUCCESS;
    }

    /**
     * Whether this many banks failing at once is better explained by Enable
     * Banking than by the banks themselves.
     *
     * It matters because of what the email asks for: every notify command in it
     * tells that bank's users their bank is broken. If the provider is down, that
     * diagnosis is wrong for all of them at once, and the outage notice is the one
     * mistake here that cannot be taken back.
     *
     * @param  Collection<int, array<string, mixed>>  $banks
     * @param  Collection<int, array<string, mixed>>  $broken
     */
    private function looksLikeProviderOutage(Collection $banks, Collection $broken): bool
    {
        $connected = $banks->where('live', '>', 0)->count();
        $down = $broken->where('live', '>', 0)->count();

        // Half of everything that has a live connection, and never on the two or
        // three banks that are simply always broken.
        return $down >= 3 && $down * 2 > $connected;
    }

    /**
     * Remember what has just been reported, so tomorrow's run stays quiet about
     * it. Four Spanish banks have been broken since before this command existed;
     * without this the daily alert would be noise from its first run.
     *
     * The cache is the whole ledger — the entry expires the window away on its own
     * — and it is written only once the email is out, because an alert lost to a
     * mail failure is worse than a second copy of one.
     *
     * @param  Collection<int, array<string, mixed>>  $banks
     */
    private function suppressUntilWindowElapses(Collection $banks): void
    {
        foreach ($banks as $bank) {
            Cache::put($this->alertKey($bank), now()->toIso8601String(), now()->addDays($this->repeatAfter()));
        }
    }

    /**
     * @param  array<string, mixed>  $bank
     */
    private function alertKey(array $bank): string
    {
        return "banking-health-alert:{$bank['key']}";
    }

    /**
     * Days of silence after a bank has been alerted about. Zero would alert every
     * day, which is what the window exists to prevent.
     */
    private function repeatAfter(): int
    {
        return max(1, (int) $this->option('repeat-after'));
    }

    private function staleHours(): int
    {
        return max(1, (int) $this->option('stale-hours'));
    }
}
