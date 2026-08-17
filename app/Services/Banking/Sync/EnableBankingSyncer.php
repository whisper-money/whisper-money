<?php

namespace App\Services\Banking\Sync;

use App\Enums\TransactionSource;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\RateLimitBackoff;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Support\Carbon;
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
        $transactionFailure = null;
        $rateLimitedUntil = null;

        $connection->load('accounts.bank');

        foreach ($connection->accounts as $account) {
            $created = 0;
            [$dateFrom, $strategy] = $this->resolveWindow($connection, $account, $dateTo, $isFirstSync);

            try {
                $created = $this->transactionSync->sync($account, $dateFrom, $dateTo, $strategy, saveDailyBalances: ! $account->isLinked());
            } catch (InaccessibleBankAccountException|WrongTransactionsPeriodException $e) {
                // A single account the bank no longer exposes, or whose history
                // window it refuses even after narrowing, must not break the
                // whole connection sync. Skip it; the user can stop syncing it
                // from the manage-accounts screen.
                Log::warning('Skipping unsyncable EnableBanking account during sync', [
                    'connection_id' => $connection->id,
                    'account_id' => $account->id,
                    'reason' => $e::class,
                ]);

                continue;
            } catch (TransientBankingProviderException $e) {
                $transactionFailure ??= $this->recordAccountTransactionFailure($account, $e);
            }

            [$balanceSynced, $balanceRateLimit] = $this->syncBalances($account, $isFirstSync);

            if (! $balanceSynced) {
                $balanceFailed++;
            }

            if ($created > 0) {
                $bankName = $account->bank->name ?? __('Unknown Bank');
                $transactionsPerBank[$bankName] = ($transactionsPerBank[$bankName] ?? 0) + $created;
            }

            // The provider asked us to stop; the accounts left would only spend
            // allowance we no longer have. Counted above first - these rows were
            // imported, and the sync log is what we read to size problems like this.
            if ($balanceRateLimit !== null) {
                $rateLimitedUntil = $balanceRateLimit;

                break;
            }
        }

        $this->finishRun($connection, $isFirstSync, $transactionFailure, $rateLimitedUntil);

        return array_filter([
            'transactions_synced' => array_sum($transactionsPerBank),
            'transactions_per_bank' => $transactionsPerBank,
            'balance_failed' => $balanceFailed,
            'rate_limited_until' => $rateLimitedUntil?->toIso8601String(),
        ], fn ($value) => $value !== null);
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
    private function recordAccountTransactionFailure(Account $account, TransientBankingProviderException $e): TransientBankingProviderException
    {
        if ($e->statusCode === null) {
            throw $e;
        }

        Log::warning('EnableBanking transaction sync failed for one account, continuing', [
            'connection_id' => $account->banking_connection_id,
            'account_id' => $account->id,
            'status_code' => $e->statusCode,
            'provider_code' => $e->providerCode,
            'error' => $e->getMessage(),
        ]);

        return $e;
    }

    /**
     * Decide what the run amounted to, once every account has had its turn.
     */
    private function finishRun(
        BankingConnection $connection,
        bool $isFirstSync,
        ?TransientBankingProviderException $transactionFailure,
        ?Carbon $rateLimitedUntil,
    ): void {
        // Report the failure only once every account has had its turn. The run
        // still fails, so the connection keeps its Error state, its retries and its
        // unset last_synced_at exactly as before - the one thing that changes is
        // that the accounts behind the failing one were attempted at all.
        //
        // Unless the provider also rate limited us, in which case its window wins:
        // throwing would drop it, and the scheduler would come straight back for
        // what is left of the allowance. The transaction failure retries next cycle
        // either way, so nothing is lost by letting the deadline take precedence.
        if ($transactionFailure !== null && $rateLimitedUntil === null) {
            throw $transactionFailure;
        }

        if ($isFirstSync) {
            $connection->update(['bank_transactions_email_cutoff_at' => now()]);
        } elseif ($connection->user->canReceiveEmails()) {
            SendDailyBankTransactionsSyncedEmailJob::dispatch($connection->user, now()->toDateString());
        }

    }

    /**
     * Whether this account still owes us the daily balances behind its transactions.
     *
     * Widens the connection's first sync rather than replacing it: an account that
     * would have been backfilled before still is. The connection-level
     * flag flips false the moment any run completes, and an account whose balance
     * call failed on that run - rate limited, or just refused - would then never get
     * its history computed: getBalanceAt carries the last balance backwards and
     * returns 0 before the first row, so the account and the net-worth chart read a
     * flat zero for every month before its first balance, permanently. The only way
     * back was an operator running `banking:sync --full`.
     *
     * Checked before the balance call, since that call writes today's row and would
     * make an account with no history look like it had some. calculateHistoricalBalances
     * skips dates it already has, so asking again costs one query and writes nothing.
     */
    private function needsHistoricalBalances(Account $account): bool
    {
        $earliestTransaction = $account->transactions()
            ->where('source', TransactionSource::EnableBanking)
            ->min('transaction_date');

        if ($earliestTransaction === null) {
            return false;
        }

        return ! $account->balances()
            ->where('balance_date', '<=', $earliestTransaction)
            ->exists();
    }

    /**
     * Sync one account's balances, tolerating a provider that will not serve them.
     *
     * @return array{0: bool, 1: Carbon|null} Whether the balances were synced, and
     *                                        when the provider will have us back if
     *                                        it rate limited us
     */
    private function syncBalances(Account $account, bool $isFirstSync): array
    {
        $needsHistory = $this->needsHistoricalBalances($account);

        try {
            $this->balanceSync->sync($account);

            if (($isFirstSync || $needsHistory) && ! $account->isLinked()) {
                $this->balanceSync->calculateHistoricalBalances($account);
            }

            return [true, null];
        } catch (\Throwable $e) {
            // An expired consent needs the user to reconnect: nothing here can help.
            if ($e instanceof ExpiredBankingSessionException) {
                throw $e;
            }

            // A rate limit still has to reach the connection so we stop asking, but
            // throwing it discarded a run whose transactions had already landed -
            // and since last_synced_at is only written on success, that connection
            // then looked like it had never synced at all. Report it instead and let
            // the caller stop the loop and hand the window to the job.
            if (RateLimitBackoff::isRateLimit($e)) {
                $until = RateLimitBackoff::until($e);

                Log::warning('EnableBanking balance sync rate limited, keeping the run', [
                    'connection_id' => $account->banking_connection_id,
                    'account_id' => $account->id,
                    'rate_limited_until' => $until->toIso8601String(),
                ]);

                return [false, $until];
            }

            // Anything else is not worth losing the run over. Balances are a
            // nice-to-have next to the transactions we just persisted, and failing
            // here leaves last_synced_at unset.
            Log::warning('EnableBanking balance sync failed, continuing', [
                'connection_id' => $account->banking_connection_id,
                'account_id' => $account->id,
                'reason' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [false, null];
        }
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
