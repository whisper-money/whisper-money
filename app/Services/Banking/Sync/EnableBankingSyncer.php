<?php

namespace App\Services\Banking\Sync;

use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;

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
