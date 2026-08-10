<?php

namespace App\Services\Banking\Sync;

use App\Enums\TransactionSource;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Support\Facades\Log;

class EnableBankingSyncer extends AbstractBankingConnectionSyncer
{
    /**
     * Days of already-synced history re-requested on every sync. Banks post
     * transactions with retroactive value dates, so starting exactly at the
     * watermark would silently miss them.
     */
    private const int WATERMARK_OVERLAP_DAYS = 3;

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

        $connection->load('accounts.bank');

        foreach ($connection->accounts as $account) {
            // Only bank-sourced rows move the watermark. A manual or
            // imported transaction dated later would otherwise shrink
            // the fetch window and skip bank history for good.
            $lastTransaction = $account->transactions()
                ->where('source', TransactionSource::EnableBanking)
                ->latest('transaction_date')
                ->first();

            // With a watermark, ask only for what came after it: re-paginating a
            // year of history on every scheduled run is what trips the provider's
            // rate limit. Without one this is the genuine first sync.
            $dateFrom = $lastTransaction
                ? $lastTransaction->transaction_date->copy()->subDays(self::WATERMARK_OVERLAP_DAYS)->toDateString()
                : now()->subYear()->toDateString();
            $strategy = $lastTransaction ? null : 'longest';

            if ($dateFrom > $dateTo) {
                $dateFrom = $dateTo;
            }

            try {
                $created = $account->isLinked()
                    ? $this->transactionSync->sync($account, $dateFrom, $dateTo, $strategy, saveDailyBalances: false)
                    : $this->transactionSync->sync($account, $dateFrom, $dateTo, $strategy);
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
            } catch (ExpiredBankingSessionException $e) {
                // Not a balance problem: the consent is gone and the whole
                // connection needs the user to reconnect.
                throw $e;
            } catch (\Throwable $e) {
                // Balances are a nice-to-have next to the transactions we just
                // persisted. Failing the run here would throw away the sync and
                // leave last_synced_at unset, which pins the connection to the
                // year-wide first-sync window forever.
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
}
