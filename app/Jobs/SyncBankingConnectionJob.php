<?php

namespace App\Jobs;

use App\Contracts\BankingConnectionSyncer;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Mail\BankingConnectionAuthFailedEmail;
use App\Mail\BankingConnectionExpiredEmail;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Services\Banking\RateLimitBackoff;
use App\Services\Banking\Sync\BankingConnectionSyncerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Sentry\State\Scope;

use function Sentry\configureScope;

class SyncBankingConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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

    public function __construct(
        public BankingConnection $bankingConnection,
        public bool $fullSync = false,
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

            // A run can succeed and still owe the provider a rest: the syncer
            // reports a rate limit it absorbed rather than throwing the whole run
            // away over it. Anything else clears the window, as a clean run should.
            $rateLimitedUntil = $metadata['rate_limited_until'] ?? null;

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
     * Classify a failed sync and give the connection the treatment it earns:
     * a provider backoff, a permanent park, or a retry.
     */
    private function handleSyncFailure(
        BankingConnection $connection,
        BankingConnectionSyncer $syncer,
        \Throwable $e,
        float $startTime,
    ): void {
        $this->reportSyncFailure($connection, $e);

        if ($this->isRateLimitError($e)) {
            $this->applyRateLimitBackoff($connection, $e);
            $this->logSyncAttempt($connection, BankingSyncLogStatus::Failed, $startTime, $e);

            return;
        }

        $this->logSyncAttempt($connection, BankingSyncLogStatus::Failed, $startTime, $e);

        if ($this->isAuthError($e)) {
            $this->handlePermanentError($connection, $syncer, $e);

            return;
        }

        $this->handleTemporaryError($connection, $e);
    }

    /**
     * Log the failure, but only once the connection actually gives up. Transient
     * errors on a non-final attempt are recovered by the retry and would otherwise
     * spam one warning per scheduled cycle for connections that ultimately sync fine.
     */
    private function reportSyncFailure(BankingConnection $connection, \Throwable $e): void
    {
        if ($this->attempts() < $this->tries && ! $this->isAuthError($e)) {
            return;
        }

        $context = [
            'connection_id' => $connection->id,
            'error' => $e->getMessage(),
            'attempt' => $this->attempts(),
        ];

        if ($e instanceof TransientBankingProviderException) {
            $context['provider'] = $e->provider;
            $context['status_code'] = $e->statusCode;
            $context['provider_code'] = $e->providerCode;
        }

        Log::log($e instanceof TransientBankingProviderException ? 'warning' : 'error', 'Banking sync failed', $context);
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
        $this->logSyncAttempt(
            $connection,
            BankingSyncLogStatus::Failed,
            startTime: null,
            error: $e,
            metadata: ['reason' => 'job_died_outside_handle'],
        );

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
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
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
        return RateLimitBackoff::isRateLimit($e);
    }

    /**
     * Persist a backoff window so the scheduler stops re-dispatching
     * the same connection until the provider quota resets.
     */
    private function applyRateLimitBackoff(BankingConnection $connection, \Throwable $e): void
    {
        $until = RateLimitBackoff::until($e);

        $connection->update([
            'rate_limited_until' => $until,
            'error_message' => $this->friendlyErrorMessage($e),
        ]);

        Log::warning('Banking connection rate limited, backing off', [
            'connection_id' => $connection->id,
            'rate_limited_until' => $until->toIso8601String(),
        ]);
    }

    private function isAuthError(\Throwable $e): bool
    {
        return $e instanceof RequestException
            && in_array($e->response->status(), [401, 403]);
    }
}
