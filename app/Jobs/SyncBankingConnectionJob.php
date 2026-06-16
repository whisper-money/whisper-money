<?php

namespace App\Jobs;

use App\Contracts\BankingConnectionSyncer;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncLogStatus;
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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
            $shouldNotify = $connection->status !== BankingConnectionStatus::Expired;

            $connection->update(['status' => BankingConnectionStatus::Expired]);
            Log::info('Banking connection expired, skipping sync', ['connection_id' => $connection->id]);

            if ($shouldNotify && $connection->user->canReceiveEmails()) {
                Mail::to($connection->user)->send(new BankingConnectionExpiredEmail(
                    $connection->user,
                    $connection,
                ));
            }

            $this->logSyncAttempt($connection, BankingSyncLogStatus::Skipped, $startTime, metadata: ['reason' => 'expired']);

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

            $connection->update([
                'status' => BankingConnectionStatus::Active,
                'last_synced_at' => $syncedAt,
                'error_message' => null,
                'rate_limited_until' => null,
                'consecutive_sync_failures' => 0,
            ]);

            $this->logSyncAttempt($connection, BankingSyncLogStatus::Success, $startTime, metadata: $metadata ?: null);
        } catch (\Throwable $e) {
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
    }

    /**
     * Handle permanent errors (auth failures) that should not be retried.
     */
    public function failed(?\Throwable $e): void
    {
        $connection = $this->bankingConnection->fresh();

        if (! $connection || $connection->status === BankingConnectionStatus::Error) {
            return;
        }

        if (! $this->isSyncableStatus($connection)) {
            return;
        }

        $connection->update([
            'status' => BankingConnectionStatus::Error,
            'error_message' => $e ? $this->friendlyErrorMessage($e) : __('An unexpected error occurred during sync. Please try again later.'),
            'consecutive_sync_failures' => $connection->consecutive_sync_failures + 1,
        ]);
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
     */
    private function handleTemporaryError(BankingConnection $connection, \Throwable $e): void
    {
        $isFinalAttempt = $this->attempts() >= $this->tries;

        if ($isFinalAttempt) {
            $connection->update([
                'status' => BankingConnectionStatus::Error,
                'error_message' => $this->friendlyErrorMessage($e),
                'consecutive_sync_failures' => $connection->consecutive_sync_failures + 1,
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

    private function logSyncAttempt(
        BankingConnection $connection,
        BankingSyncLogStatus $status,
        float $startTime,
        ?\Throwable $error = null,
        ?array $metadata = null,
    ): void {
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        BankingSyncLog::create([
            'banking_connection_id' => $connection->id,
            'status' => $status,
            'attempt' => $this->attempts(),
            'error_message' => $error?->getMessage(),
            'error_class' => $error ? get_class($error) : null,
            'duration_ms' => $durationMs,
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
        return $e instanceof RequestException && $e->response->status() === 429;
    }

    /**
     * Persist a backoff window so the scheduler stops re-dispatching
     * the same connection until the provider quota resets.
     */
    private function applyRateLimitBackoff(BankingConnection $connection, \Throwable $e): void
    {
        $until = $this->resolveRateLimitBackoffUntil($e);

        $connection->update([
            'rate_limited_until' => $until,
            'error_message' => $this->friendlyErrorMessage($e),
        ]);

        Log::warning('Banking connection rate limited, backing off', [
            'connection_id' => $connection->id,
            'rate_limited_until' => $until->toIso8601String(),
        ]);
    }

    private function resolveRateLimitBackoffUntil(\Throwable $e): Carbon
    {
        $now = now();

        if ($e instanceof RequestException) {
            $retryAfter = $e->response->header('Retry-After');

            if (is_numeric($retryAfter) && (int) $retryAfter > 0) {
                return $now->copy()->addSeconds((int) $retryAfter);
            }

            $body = $e->response->json();
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';

            // Daily PSU consultation limit resets at midnight UTC.
            if (str_contains(strtolower($message), 'daily')) {
                return $now->copy()->utc()->addDay()->startOfDay();
            }
        }

        // Default: back off one hour for consent / generic 429 responses.
        return $now->copy()->addHour();
    }

    private function isAuthError(\Throwable $e): bool
    {
        return $e instanceof RequestException
            && in_array($e->response->status(), [401, 403]);
    }
}
