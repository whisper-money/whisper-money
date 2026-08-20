<?php

namespace App\Services\Banking\Sync;

use App\Enums\TransactionSource;
use App\Exceptions\Banking\CarriesBankingOperation;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class EnableBankingSyncer extends AbstractBankingConnectionSyncer
{
    /**
     * Days of already-synced history re-requested on every sync. Banks post
     * transactions with retroactive value dates, so starting exactly at the
     * watermark would silently miss them.
     */
    private const int WATERMARK_OVERLAP_DAYS = 3;

    /**
     * Windows reaching further back than this still ask for the 'longest'
     * strategy. Banks refuse wide unattended windows, and the narrowing retry
     * that follows would skip the history in between for good.
     */
    private const int SHORT_WINDOW_DAYS = 90;

    public function __construct(
        private TransactionSyncService $transactionSync,
        private BalanceSyncService $balanceSync,
    ) {}

    public function expires(): bool
    {
        return true;
    }

    public function notifiesOnAuthFailure(): bool
    {
        return false;
    }

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $dateTo = now()->toDateString();

        $transactionsPerBank = [];
        $balanceFailed = 0;
        $balanceRateLimitFailure = null;
        $transactionFailure = null;

        $accountsAttempted = 0;

        $connection->load('accounts.bank');

        try {
            foreach ($connection->accounts as $account) {
                $created = 0;
                $accountsAttempted++;
                [$dateFrom, $strategy] = $this->resolveWindow($connection, $account, $dateTo, $isFirstSync);

                try {
                    $created = $this->transactionSync->sync($account, $dateFrom, $dateTo, $strategy, saveDailyBalances: ! $account->isLinked());
                } catch (InaccessibleBankAccountException|WrongTransactionsPeriodException $e) {
                    // A single account the bank no longer exposes, or whose history
                    // window it refuses even after narrowing, must not break the
                    // whole connection sync. Skip it; the user can stop syncing it
                    // from the manage-accounts screen.
                    Log::warning('Skipping unsyncable EnableBanking account during sync', [
                        ...$connection->logContext(),
                        'account_id' => $account->id,
                        'reason' => $e::class,
                    ]);

                    continue;
                } catch (TransientBankingProviderException $e) {
                    $transactionFailure ??= $this->recordAccountTransactionFailure($connection, $account, $e);
                }

                $transactionsPerBank = $this->tally($transactionsPerBank, $account, $created);

                if (! $this->syncBalances($connection, $account, $isFirstSync, $balanceRateLimitFailure)) {
                    $balanceFailed++;
                }
            }
        } catch (\Throwable $e) {
            $this->logAbortedSync($connection, $e, [
                'accounts_attempted' => $accountsAttempted,
                'accounts_total' => $connection->accounts->count(),
                'transactions_synced' => array_sum($transactionsPerBank),
                'balance_failed' => $balanceFailed,
                'balance_rate_limited' => $balanceRateLimitFailure !== null,
            ]);

            throw $e;
        }

        // Report the failure only once every account has had its turn. The run
        // still fails, so the connection keeps its Error state, its retries and its
        // unset last_synced_at exactly as before - the one thing that changes is
        // that the accounts behind the failing one were attempted at all.
        //
        // Outside the try on purpose: by here nothing was aborted mid-run, every
        // account was attempted, and the per-account failure is already logged.
        if ($transactionFailure !== null) {
            // A balance rate limit has no channel once the run throws instead of
            // returning its metadata, and the job would retry straight into an
            // allowance it knows is spent. Handing it the 429 instead gets the
            // backoff applied exactly as it was before the run was worth keeping.
            throw $balanceRateLimitFailure ?? $transactionFailure;
        }

        if ($isFirstSync) {
            $connection->update(['bank_transactions_email_cutoff_at' => now()]);
        } elseif ($connection->user->canReceiveEmails()) {
            SendDailyBankTransactionsSyncedEmailJob::dispatch($connection->user, now()->toDateString());
        }

        return [
            'transactions_synced' => array_sum($transactionsPerBank),
            'transactions_per_bank' => $transactionsPerBank,
            'balance_failed' => $balanceFailed,
            'balance_rate_limit' => $this->rateLimitFacts($balanceRateLimitFailure),
        ];
    }

    /**
     * Record what the run had already got done when it died.
     *
     * A throwing run returns no metadata, so "this connection imported six
     * transactions and then the rate limit killed the run" was until now only
     * reconstructable by lining up timestamps across two tables.
     *
     * @param  array<string, bool|int>  $progress
     */
    private function logAbortedSync(BankingConnection $connection, \Throwable $e, array $progress): void
    {
        // A consent that lapsed mid-run is a lifecycle event the job already
        // records, not a failure worth a second warning.
        if ($e instanceof ExpiredBankingSessionException) {
            return;
        }

        Log::warning('EnableBanking sync aborted mid-run', [
            ...$connection->logContext(),
            ...$progress,
            'operation' => $e instanceof CarriesBankingOperation ? $e->operation : null,
            'status_code' => $e instanceof RequestException ? $e->response->status() : null,
            'reason' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * The window to ask the bank for, and the strategy a window that wide needs.
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolveWindow(BankingConnection $connection, Account $account, string $dateTo, bool $isFirstSync): array
    {
        // A first sync on a connection that has synced before can only come from
        // the --full flag, an explicit request to re-pull the whole history.
        $forceFullWindow = $isFirstSync && $connection->last_synced_at !== null;

        $dateFrom = $this->resolveDateFrom($account, $dateTo, $forceFullWindow);
        $shortWindowStart = now()->subDays(self::SHORT_WINDOW_DAYS)->toDateString();

        return [$dateFrom, $dateFrom < $shortWindowStart ? 'longest' : null];
    }

    /**
     * Note that the bank could not serve one account's transactions, and decide
     * whether the remaining accounts are still worth trying.
     *
     * A provider that never answered will not answer for the next account either,
     * and each further attempt costs the client's full timeout against the job's
     * 120s - a connection with 26 accounts already spends a minute on the happy
     * path. Only a reply that came back with a status says something about *this*
     * account: the ConnectionException path is the one that leaves statusCode null.
     */
    private function recordAccountTransactionFailure(BankingConnection $connection, Account $account, TransientBankingProviderException $e): TransientBankingProviderException
    {
        if ($e->statusCode === null) {
            throw $e;
        }

        Log::warning('EnableBanking transaction sync failed for one account, continuing', [
            ...$connection->logContext(),
            'account_id' => $account->id,
            'status_code' => $e->statusCode,
            'provider_code' => $e->providerCode,
            'operation' => $e->operation,
            'error' => $e->getMessage(),
        ]);

        return $e;
    }

    /**
     * Sync one account's balances, tolerating a provider that will not serve them.
     *
     * `$rateLimitFailure` is filled in with the 429 when the provider rate limited
     * the call, so the caller can hand the job what its backoff policy needs.
     *
     * Arriving with it already filled in means an earlier account in the same run
     * was rate limited: the allowance is spent for the whole consent, so this call
     * is not made at all rather than burning what is left of it.
     *
     * @return bool Whether the balances were synced
     */
    private function syncBalances(BankingConnection $connection, Account $account, bool $isFirstSync, ?RequestException &$rateLimitFailure): bool
    {
        if ($rateLimitFailure !== null) {
            return false;
        }

        try {
            $this->balanceSync->sync($account);

            if ($isFirstSync && ! $account->isLinked()) {
                $this->balanceSync->calculateHistoricalBalances($account);
            }

            return true;
        } catch (\Throwable $e) {
            // An expired consent needs the user to reconnect, so it still has to
            // reach the job.
            if ($e instanceof ExpiredBankingSessionException) {
                throw $e;
            }

            // A rate limit used to be rethrown so the job would apply the provider
            // backoff, which threw away a run whose transactions were already
            // persisted - and with them last_synced_at. The backoff is the part
            // worth keeping, so it is reported instead of thrown: without it the
            // remaining scheduled runs keep burning the daily allowance.
            if ($e instanceof RequestException && $e->response->status() === 429) {
                $rateLimitFailure = $e;
            }

            // No balance failure is worth losing the run over. Balances are a
            // nice-to-have next to the transactions we just persisted, and failing
            // here leaves last_synced_at unset.
            Log::warning('EnableBanking balance sync failed, continuing', [
                ...$connection->logContext(),
                'account_id' => $account->id,
                'operation' => 'balances',
                'reason' => $e::class,
                'status_code' => $e instanceof RequestException ? $e->response->status() : null,
                'rate_limited' => $rateLimitFailure !== null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Tally what one account imported, under the bank it came from.
     *
     * Counted before the balances call, which is the one that throws most often:
     * a tally taken after it would report zero imported for a run that had in
     * fact just imported them.
     *
     * @param  array<string, int>  $transactionsPerBank
     * @return array<string, int>
     */
    private function tally(array $transactionsPerBank, Account $account, int $created): array
    {
        if ($created <= 0) {
            return $transactionsPerBank;
        }

        $bankName = $account->bank->name ?? __('Unknown Bank');
        $transactionsPerBank[$bankName] = ($transactionsPerBank[$bankName] ?? 0) + $created;

        return $transactionsPerBank;
    }

    /**
     * What the provider's 429 said, in the JSON-safe shape the job's backoff
     * policy reads - the exception itself cannot go in the metadata, which is
     * persisted as JSON on the sync log. Null when no balance call was limited.
     *
     * @return array{retry_after: string|null, message: string}|null
     */
    private function rateLimitFacts(?RequestException $e): ?array
    {
        if ($e === null) {
            return null;
        }

        $body = $e->response->json();

        return [
            'retry_after' => $e->response->header('Retry-After') ?: null,
            'message' => is_array($body) ? (string) ($body['message'] ?? '') : '',
        ];
    }

    /**
     * Start of the window to fetch for an account: just before the last
     * transaction the bank sent us, or a year back when it never sent one.
     *
     * Asking for what came after the watermark is what keeps a routine sync to
     * a handful of days. Re-paginating a year on every scheduled run is what
     * trips the provider's rate limit.
     */
    private function resolveDateFrom(Account $account, string $dateTo, bool $forceFullWindow): string
    {
        // Only bank-sourced rows move the watermark: a manual or imported
        // transaction dated later would shrink the window and skip bank history
        // for good. Trashed rows still count, because the dedup that follows
        // sees them too and would re-import nothing anyway.
        $watermark = $forceFullWindow ? null : $account->transactions()
            ->withTrashed()
            ->where('source', TransactionSource::EnableBanking)
            ->latest('transaction_date')
            ->value('transaction_date');

        if (! $watermark) {
            return now()->subYear()->toDateString();
        }

        // Future-dated rows (standing orders) must not push the window past
        // today, but the overlap applies either way.
        $start = $watermark->toDateString() > $dateTo ? now() : $watermark;

        return $start->copy()->subDays(self::WATERMARK_OVERLAP_DAYS)->toDateString();
    }
}
