<?php

namespace App\Jobs;

use App\Contracts\BankingConnectionSyncer;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
use App\Enums\BankingSyncTrigger;
use App\Exceptions\Banking\CarriesBankingOperation;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Mail\BankingConnectionAuthFailedEmail;
use App\Mail\BankingConnectionExpiredEmail;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Services\Banking\Sync\BankingConnectionSyncerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Sentry\State\Scope;

use function Sentry\configureScope;

class SyncBankingConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Two attempts, not three.
     *
     * The third one recovered 2 of 176 runs over a fortnight in production
     * (1.1%), and it is not free: an attempt re-syncs every account on the
     * connection - transactions and balances - against a consent the bank meters
     * at a couple of accesses a day, so it mostly spends the allowance the next
     * scheduled cycle needs. The second attempt does earn its keep, at 58
     * recoveries in 262 (22%), which is why this is 2 and not 1.
     *
     * A failure that outlives both attempts is not lost: the connection stays in
     * the scheduled rotation and is tried again on the next cycle.
     */
    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 120;

    /**
     * Safety TTL for the unique lock in case a worker dies mid-run, matching the
     * sibling unique jobs. Without it a lock lost to a hard kill is never
     * released, and since uniqueId() is the connection id, that one connection
     * silently stops syncing for good - the same dead end this class of bug keeps
     * producing. Comfortably longer than tries x (timeout + backoff).
     */
    public int $uniqueFor = 1800;

    /**
     * Maximum number of scheduled sync cycles that will auto-retry
     * a connection in Error state before requiring manual intervention.
     */
    public const int MAX_SCHEDULED_RETRIES = 3;

    /**
     * Response headers logged verbatim on a failure, so that "the provider told
     * us to wait N" is distinguishable from "we defaulted to an hour".
     *
     * @var list<string>
     */
    private const array RATE_LIMIT_HEADERS = [
        'Retry-After',
        'RateLimit-Limit',
        'RateLimit-Remaining',
        'RateLimit-Reset',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ];

    /**
     * @param  BankingSyncTrigger  $trigger  Who asked for this sync. Third and
     *                                       last because callers pass $fullSync
     *                                       positionally; a constructor property
     *                                       survives serialization, so failed()
     *                                       can read it off its fresh instance
     *                                       and an in-flight payload written
     *                                       before this existed unserializes to
     *                                       the default.
     */
    public function __construct(
        public BankingConnection $bankingConnection,
        public bool $fullSync = false,
        public BankingSyncTrigger $trigger = BankingSyncTrigger::Scheduled,
    ) {}

    public function uniqueId(): string
    {
        return $this->bankingConnection->id;
    }

    public function handle(BankingConnectionSyncerFactory $syncerFactory): void
    {
        $connection = $this->bankingConnection;
        $startTime = microtime(true);
        $syncedAt = now();

        $connection->loadMissing('user');
        $this->setSentryContext($connection);

        if (! $connection->user) {
            Log::info('Banking connection belongs to deleted user, skipping sync', ['connection_id' => $connection->id]);

            $this->logSyncAttempt($connection, BankingSyncLogStatus::Skipped, $startTime, metadata: ['reason' => 'deleted_user']);

            return;
        }

        $syncer = $syncerFactory->make($connection);

        if ($syncer->expires() && $connection->isExpired()) {
            $this->markExpired($connection, $startTime);

            return;
        }

        if (! $this->isSyncableStatus($connection)) {
            $this->logSyncAttempt($connection, BankingSyncLogStatus::Skipped, $startTime, metadata: ['reason' => 'not_syncable', 'status' => $connection->status->value]);

            return;
        }

        if ($connection->isRateLimited()) {
            Log::info('Banking connection rate limited, skipping sync', [
                'connection_id' => $connection->id,
                'rate_limited_until' => $connection->rate_limited_until?->toIso8601String(),
            ]);

            $this->logSyncAttempt($connection, BankingSyncLogStatus::Skipped, $startTime, metadata: [
                'reason' => 'rate_limited',
                'rate_limited_until' => $connection->rate_limited_until?->toIso8601String(),
            ]);

            return;
        }

        try {
            $isFirstSync = ! $connection->last_synced_at || $this->fullSync;

            $metadata = $syncer->sync($connection, $isFirstSync);
            $rateLimitedUntil = $this->balanceRateLimitBackoff($connection, $metadata);

            $connection->update([
                'status' => BankingConnectionStatus::Active,
                'last_synced_at' => $syncedAt,
                'error_message' => null,
                'rate_limited_until' => $rateLimitedUntil,
                'consecutive_sync_failures' => 0,
            ]);

            $this->logSyncAttempt($connection, BankingSyncLogStatus::Success, $startTime, metadata: $metadata ?: null);
        } catch (ExpiredBankingSessionException) {
            $this->markExpired($connection, $startTime);

            return;
        } catch (\Throwable $e) {
            $this->handleSyncFailure($connection, $syncer, $e, $startTime);
        }
    }

    /**
     * Classify a failed run, record it, and leave the connection in the state
     * that classification calls for.
     */
    private function handleSyncFailure(
        BankingConnection $connection,
        BankingConnectionSyncer $syncer,
        \Throwable $e,
        float $startTime,
    ): void {
        $context = $this->failureContext($connection, $e);

        // Only report once the connection actually gives up. Transient errors on a
        // non-final attempt are recovered by the retry and would otherwise spam one
        // warning per scheduled cycle for connections that ultimately sync fine.
        if ($this->attempts() >= $this->tries || $this->isAuthError($e)) {
            Log::log($e instanceof TransientBankingProviderException ? 'warning' : 'error', 'Banking sync failed', $context);
        }

        if ($this->isRateLimitError($e)) {
            $this->applyRateLimitBackoff($connection, $e, $context);
            $this->logSyncAttempt($connection, BankingSyncLogStatus::Failed, $startTime, $e, $context);

            return;
        }

        $this->logSyncAttempt($connection, BankingSyncLogStatus::Failed, $startTime, $e, $context);

        if ($this->isAuthError($e)) {
            $this->handlePermanentError($connection, $syncer, $e);

            return;
        }

        $this->handleTemporaryError($connection, $e);
    }

    /**
     * Last resort for a job that died outside handle()'s own error handling.
     *
     * Everything raised inside handle() is already classified and recorded, and
     * leaves the connection in Error - which the status guard below returns on,
     * after the death itself has been recorded. So what reaches here is the job
     * being killed from the outside: the queue worker's timeout, an exhausted
     * retry count, the worker being restarted mid-sync.
     *
     * None of that is evidence the *connection* is broken, so it must not be
     * charged to the connection's budget of scheduled retries. That budget is
     * what keeps it in the scheduled rotation at all, and spending it here means
     * spending it on our own infrastructure.
     *
     * Note the guard makes this at most one increment per connection lifetime -
     * a second out-of-band death finds the connection already in Error and does
     * nothing - so removing it is a small correction, not a fix for a runaway
     * counter. It closes the one route that could reach the ceiling this way:
     * a reconnect that left the counter at MAX - 1 (see AuthorizationController)
     * followed by a single job death.
     */
    public function failed(?\Throwable $e): void
    {
        $connection = $this->bankingConnection->fresh();

        if (! $connection) {
            return;
        }

        // Recorded before the status guards, not after them. handle() owns every
        // other logSyncAttempt call and an out-of-band death skips all of them, so
        // this is the connection's only trace of the most common way this job dies
        // - and the deaths that repeat are exactly the ones the guards drop. The
        // connection this was written for is already parked in Error and stays
        // there: 68 failed jobs against 3 sync-log rows, and logging after the
        // guard would have added none of the missing 65.
        //
        // Only for a death handle() did not already record, though: a failure it
        // classified is in the table with its duration and its real error, and
        // repeating it here as `job_died_outside_handle` both doubles the row and
        // misattributes the cause.
        if (! $this->alreadyRecordedThisAttempt($connection)) {
            $this->logSyncAttempt(
                $connection,
                BankingSyncLogStatus::Failed,
                startTime: null,
                error: $e,
                metadata: ['reason' => 'job_died_outside_handle'],
            );
        }

        if ($connection->status === BankingConnectionStatus::Error || ! $this->isSyncableStatus($connection)) {
            return;
        }

        $connection->update([
            'status' => BankingConnectionStatus::Error,
            // Every message written here describes an out-of-band death, so the
            // generic "an unexpected error occurred, please try again" was pushing
            // our own infrastructure onto the user. The next scheduled cycle picks
            // the connection back up on its own.
            'error_message' => __('The sync did not finish. We will try again later.'),
        ]);
    }

    /**
     * Whether the attempt that just died already recorded its own failure.
     *
     * It cannot be answered with a flag on the job: the queue unserializes a
     * fresh instance of this class to call failed() on, so nothing handle() set
     * survives the trip. The row it left behind does, and an in-band failure
     * writes one moments before rethrowing - which is what brings us here - so a
     * failed row for this same attempt, seconds old, is that row and not another
     * cycle's. Scheduled cycles are hours apart, so there is nothing else it
     * could be.
     */
    private function alreadyRecordedThisAttempt(BankingConnection $connection): bool
    {
        return $connection->syncLogs()
            ->where('status', BankingSyncLogStatus::Failed)
            ->where('attempt', $this->attempts())
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }

    /**
     * Mark the connection as expired and notify the user to reconnect.
     *
     * Reached both when the stored consent window lapses and when the provider
     * reports the session itself has expired mid-sync. Either way it is an
     * expected lifecycle event, not a failure to report.
     */
    private function markExpired(BankingConnection $connection, float $startTime): void
    {
        $shouldNotify = $connection->status !== BankingConnectionStatus::Expired;

        $connection->update(['status' => BankingConnectionStatus::Expired]);
        Log::info('Banking connection expired, skipping sync', ['connection_id' => $connection->id]);

        if ($shouldNotify && $connection->user?->canReceiveEmails()) {
            Mail::to($connection->user)->send(new BankingConnectionExpiredEmail(
                $connection->user,
                $connection,
            ));
        }

        $this->logSyncAttempt($connection, BankingSyncLogStatus::Skipped, $startTime, metadata: ['reason' => 'expired']);
    }

    private function handlePermanentError(BankingConnection $connection, BankingConnectionSyncer $syncer, \Throwable $e): void
    {
        $connection->update([
            'status' => BankingConnectionStatus::Error,
            'error_message' => $this->friendlyErrorMessage($e),
            'consecutive_sync_failures' => self::MAX_SCHEDULED_RETRIES + 1,
        ]);

        if ($syncer->notifiesOnAuthFailure() && $connection->user?->canReceiveEmails()) {
            Mail::to($connection->user)->send(new BankingConnectionAuthFailedEmail(
                $connection->user,
                $connection,
            ));
        }

        $this->fail($e);

        throw $e;
    }

    /**
     * Handle temporary errors that may resolve on retry.
     *
     * A provider outage must not spend the connection's budget of scheduled
     * retries: MAX_SCHEDULED_RETRIES failures drop it out of every future
     * scheduled sync, silently and with no way back other than reconnecting.
     * Reaching that state because the bank was down for three cycles is the
     * wrong trade, so a classified-transient failure surfaces on the
     * connection without moving the counter.
     *
     * ponytail: a provider that is down forever is then retried forever. That
     * costs one job per cycle and shows up as a repeating warning; add a cap
     * if the retries ever become expensive.
     */
    private function handleTemporaryError(BankingConnection $connection, \Throwable $e): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $isTransient = $e instanceof TransientBankingProviderException;

            $connection->update([
                'status' => BankingConnectionStatus::Error,
                'error_message' => $this->friendlyErrorMessage($e),
                'consecutive_sync_failures' => $isTransient
                    ? $connection->consecutive_sync_failures
                    : $connection->consecutive_sync_failures + 1,
            ]);
        }

        throw $e;
    }

    /**
     * Whether the connection status allows syncing.
     * Allows both Active and Error (for auto-retry from scheduled runs).
     */
    private function isSyncableStatus(BankingConnection $connection): bool
    {
        return in_array($connection->status, [
            BankingConnectionStatus::Active,
            BankingConnectionStatus::Error,
        ]);
    }

    private function setSentryContext(BankingConnection $connection): void
    {
        configureScope(function (Scope $scope) use ($connection): void {
            $scope->setTag('banking_connection_id', (string) $connection->id);
            $scope->setContext('banking_connection', [
                'id' => $connection->id,
                'provider' => $connection->provider->value,
                'status' => $connection->status->value,
            ]);

            if ($connection->user === null) {
                return;
            }

            $scope->setUser([
                'id' => (string) $connection->user->getAuthIdentifier(),
                'email' => $connection->user->email,
            ]);
        });
    }

    /**
     * @param  float|null  $startTime  Null when the caller never got to start a
     *                                 timer, so the duration is genuinely unknown
     *                                 rather than zero.
     */
    private function logSyncAttempt(
        BankingConnection $connection,
        BankingSyncLogStatus $status,
        ?float $startTime,
        ?\Throwable $error = null,
        ?array $metadata = null,
    ): void {
        BankingSyncLog::create([
            'banking_connection_id' => $connection->id,
            'status' => $status,
            'attempt' => $this->attempts(),
            'error_message' => $error?->getMessage(),
            'error_class' => $error ? get_class($error) : null,
            'duration_ms' => $startTime === null
                ? null
                : (int) round((microtime(true) - $startTime) * 1000),
            // On every row, including the ones that carry nothing else, so the
            // trigger can be counted without a join or a null branch.
            'metadata' => ['trigger' => $this->trigger->value, ...($metadata ?? [])],
            'created_at' => now(),
        ]);
    }

    /**
     * Everything worth knowing about a failed sync, shared by the warning that
     * reaches Sentry and the sync-log row that stays in the database.
     *
     * @return array<string, mixed>
     */
    private function failureContext(BankingConnection $connection, \Throwable $e): array
    {
        $context = [
            ...$connection->logContext(),
            'operation' => $e instanceof CarriesBankingOperation ? $e->operation : null,
            'error' => $e->getMessage(),
            'error_class' => $e::class,
            'attempt' => $this->attempts(),
        ];

        if ($e instanceof TransientBankingProviderException) {
            $context['provider'] = $e->provider;
            $context['provider_code'] = $e->providerCode;
        }

        return [...$context, ...$this->responseContext($e)];
    }

    /**
     * What the provider actually replied, for the failures that got a reply.
     *
     * @return array<string, mixed>
     */
    private function responseContext(\Throwable $e): array
    {
        $failure = $this->requestFailure($e);

        if ($failure === null) {
            return [];
        }

        return [
            'status_code' => $failure->response->status(),
            // Error bodies only - a RequestException never carries a successful
            // payload - and truncated, so that a provider which starts answering
            // with account data on an error status cannot empty it into the logs.
            'response_body' => Str::limit($failure->response->body(), 500),
            'rate_limit_headers' => $this->rateLimitHeaders($failure->response),
        ];
    }

    /**
     * The HTTP failure behind an exception, whether it is the exception itself
     * or the one the provider wrapped.
     */
    private function requestFailure(\Throwable $e): ?RequestException
    {
        if ($e instanceof RequestException) {
            return $e;
        }

        $previous = $e->getPrevious();

        return $previous instanceof RequestException ? $previous : null;
    }

    /**
     * The provider's own account of the limit it is enforcing.
     *
     * Allow-listed rather than dumped wholesale: response headers are the one
     * place a token or a cookie could ride along into a log this project keeps
     * in public.
     *
     * @return array<string, string>
     */
    private function rateLimitHeaders(Response $response): array
    {
        $headers = [];

        foreach (self::RATE_LIMIT_HEADERS as $name) {
            $value = $response->header($name);

            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function friendlyErrorMessage(\Throwable $e): string
    {
        if ($e instanceof TransientBankingProviderException) {
            return __('The bank provider is temporarily unavailable. We will try syncing again later.');
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return match (true) {
                $status === 429 => __('Rate limit exceeded. Please wait a few minutes and try again.'),
                $status === 401 || $status === 403 => __('Authentication failed. Your credentials may have expired or been revoked.'),
                $status >= 500 => __('The provider is experiencing issues. Please try again later.'),
                default => __('Failed to sync with the provider. Please try again later.'),
            };
        }

        return __('An unexpected error occurred during sync. Please try again later.');
    }

    private function isRateLimitError(\Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 429;
    }

    /**
     * Persist a backoff window so the scheduler stops re-dispatching
     * the same connection until the provider quota resets.
     *
     * This is the only failure path that records a message while leaving the
     * status Active, and the connections page relies on that: an active,
     * never-synced connection carrying a message is rendered as waiting for the
     * bank, with copy that names a rate limit. A second path that stored a
     * message without flipping the status would tell users the wrong story.
     *
     * @param  array<string, mixed>  $context
     */
    private function applyRateLimitBackoff(BankingConnection $connection, \Throwable $e, array $context): void
    {
        $until = $this->resolveRateLimitBackoffUntil($e);

        $connection->update([
            'rate_limited_until' => $until,
            'error_message' => $this->friendlyErrorMessage($e),
        ]);

        // The line above this one only fires on the final attempt, so for a rate
        // limit this is usually the only record that reaches the logs. It carries
        // the full context for that reason.
        Log::warning('Banking connection rate limited, backing off', [
            ...$context,
            'rate_limited_until' => $until->toIso8601String(),
        ]);
    }

    private function resolveRateLimitBackoffUntil(\Throwable $e): Carbon
    {
        if (! $e instanceof RequestException) {
            return $this->backoffUntil(null, '');
        }

        $body = $e->response->json();

        return $this->backoffUntil(
            $e->response->header('Retry-After'),
            is_array($body) ? (string) ($body['message'] ?? '') : '',
        );
    }

    /**
     * The backoff a *successful* run still has to carry, because the provider
     * rate limited the balance call after the transactions had already been
     * persisted. Null on every other run, which is what clears an old backoff.
     *
     * The exception cannot travel in the metadata - it is persisted as JSON on
     * the sync log - so the syncer reports the two facts the policy keys on.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function balanceRateLimitBackoff(BankingConnection $connection, array $metadata): ?Carbon
    {
        $rateLimit = $metadata['balance_rate_limit'] ?? null;

        if (! is_array($rateLimit)) {
            return null;
        }

        $retryAfter = $rateLimit['retry_after'] ?? null;
        $message = (string) ($rateLimit['message'] ?? '');
        $until = $this->backoffUntil(is_string($retryAfter) ? $retryAfter : null, $message);

        // Same message as the failed-run path, which is what these get searched
        // for. It carries what the failed path reads off the response - which of
        // the two limits this is, and whether the wait is the provider's or our
        // default - under different keys, because here they come from the
        // metadata rather than from a response this class never sees.
        Log::warning('Banking connection rate limited, backing off', [
            ...$connection->logContext(),
            'operation' => 'balances',
            'status_code' => 429,
            'provider_message' => $message,
            'retry_after' => $retryAfter,
            'rate_limited_until' => $until->toIso8601String(),
            'run_recorded_as' => BankingSyncLogStatus::Success->value,
        ]);

        return $until;
    }

    /**
     * How long to stop asking the provider for, given what its 429 said.
     *
     * Shared by the failed run, which still has the exception in hand, and the
     * run that succeeded with only its balance call rate limited, which has the
     * JSON-safe facts the syncer put in the metadata.
     */
    private function backoffUntil(?string $retryAfter, string $message): Carbon
    {
        $now = now();

        if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
            return $now->copy()->addSeconds((int) $retryAfter);
        }

        if ($this->isExhaustedAccessAllowance($message)) {
            return $now->copy()->utc()->addDay()->startOfDay();
        }

        // Default: back off one hour for a burst limit we know nothing else about.
        return $now->copy()->addHour();
    }

    /**
     * Whether the provider is reporting a spent allowance rather than a burst.
     *
     * PSD2 budgets unattended access per consent per day, so these do not come back
     * in an hour - they come back when the day does. Matched on the wordings the
     * banks actually send, counted over 45 days of banking_sync_logs: "[HUB046]
     * Allowed number of accesses exceeded for consent." (234), "Access exceeded"
     * (94), "Maximum daily access exceeded" (48), "The access on the account has
     * been exceeding the consented multiplicity per day." (37), "Daily PSU not
     * present consultation limit has been exceeded" (11), and a localised pair,
     * "CLO03941 - Operación no disponible. Has superado el número máximo de
     * accesos." (4) plus its Catalan twin (1).
     *
     * ponytail: prose matching, because there is nothing better to key on -
     * detail.error_name is `RateLimitException` for a spent daily allowance and for
     * a plain burst alike. If Enable Banking ever separates the two, key on that.
     * Note error_message only holds the first 120 bytes of the body, so a future
     * attempt at a structured field has to start by logging the whole thing.
     */
    private function isExhaustedAccessAllowance(string $message): bool
    {
        return Str::contains($message, [
            'daily',
            'access exceeded',
            'accesses exceeded',
            'exceeding the consented',
            'máximo de accesos',
            'màxim d’accessos',
        ], ignoreCase: true);
    }

    private function isAuthError(\Throwable $e): bool
    {
        return $e instanceof RequestException
            && in_array($e->response->status(), [401, 403]);
    }
}
