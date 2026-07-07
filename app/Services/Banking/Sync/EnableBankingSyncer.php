<?php

namespace App\Services\Banking\Sync;

use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Support\Facades\Log;

class EnableBankingSyncer extends AbstractBankingConnectionSyncer
{
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
        $dateFrom = $isFirstSync
            ? now()->subYear()->toDateString()
            : ($connection->last_synced_at?->toDateString() ?? now()->subMonth()->toDateString());
        $dateTo = now()->toDateString();
        $strategy = $isFirstSync ? 'longest' : null;

        $transactionsPerBank = [];

        $connection->load('accounts.bank');

        foreach ($connection->accounts as $account) {
            try {
                if ($account->isLinked()) {
                    $lastTransaction = $account->transactions()
                        ->latest('transaction_date')
                        ->first();

                    $linkedDateFrom = $lastTransaction
                        ? $lastTransaction->transaction_date->toDateString()
                        : $dateFrom;

                    if ($linkedDateFrom > $dateTo) {
                        $linkedDateFrom = $dateTo;
                    }

                    $created = $this->transactionSync->sync($account, $linkedDateFrom, $dateTo, $strategy, saveDailyBalances: false);
                    $this->balanceSync->sync($account);
                } else {
                    $created = $this->transactionSync->sync($account, $dateFrom, $dateTo, $strategy);
                    $this->balanceSync->sync($account);

                    if ($isFirstSync) {
                        $this->balanceSync->calculateHistoricalBalances($account);
                    }
                }
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

        return ['transactions_synced' => array_sum($transactionsPerBank), 'transactions_per_bank' => $transactionsPerBank];
    }
}
