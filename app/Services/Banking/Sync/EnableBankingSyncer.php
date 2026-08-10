<?php

namespace App\Services\Banking\Sync;

use App\Enums\TransactionSource;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
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
        $shortWindowStart = now()->subDays(self::SHORT_WINDOW_DAYS)->toDateString();

        // A first sync on a connection that has synced before can only come from
        // the --full flag, an explicit request to re-pull the whole history.
        $forceFullWindow = $isFirstSync && $connection->last_synced_at !== null;

        $transactionsPerBank = [];
        $balanceFailed = 0;

        $connection->load('accounts.bank');

        foreach ($connection->accounts as $account) {
            $dateFrom = $this->resolveDateFrom($account, $dateTo, $forceFullWindow);
            $strategy = $dateFrom < $shortWindowStart ? 'longest' : null;

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
            }

            try {
                $this->balanceSync->sync($account);

                if ($isFirstSync && ! $account->isLinked()) {
                    $this->balanceSync->calculateHistoricalBalances($account);
                }
            } catch (\Throwable $e) {
                // An expired consent needs the user to reconnect, and a rate
                // limit has to reach the job so it applies the provider backoff:
                // swallowing it would keep burning the remaining daily quota.
                if ($e instanceof ExpiredBankingSessionException || $this->isRateLimit($e)) {
                    throw $e;
                }

                // Anything else is not worth losing the run over. Balances are a
                // nice-to-have next to the transactions we just persisted, and
                // failing here leaves last_synced_at unset.
                $balanceFailed++;

                Log::warning('EnableBanking balance sync failed, continuing', [
                    'connection_id' => $connection->id,
                    'account_id' => $account->id,
                    'reason' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($created > 0) {
                $bankName = $account->bank->name ?? __('Unknown Bank');
                $transactionsPerBank[$bankName] = ($transactionsPerBank[$bankName] ?? 0) + $created;
            }
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

    private function isRateLimit(\Throwable $e): bool
    {
        return $e instanceof RequestException && $e->response->status() === 429;
    }
}
