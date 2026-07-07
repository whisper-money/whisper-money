<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\PlaidBalanceSyncService;
use App\Services\Banking\PlaidClient;
use App\Services\Banking\PlaidTransactionSyncService;

class PlaidSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(
        private PlaidTransactionSyncService $transactionSync,
        private PlaidBalanceSyncService $balanceSync,
    ) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $dateFrom = $isFirstSync
            ? now()->subYear()->toDateString()
            : ($connection->last_synced_at?->toDateString() ?? now()->subMonth()->toDateString());
        $dateTo = now()->toDateString();

        $client = new PlaidClient($connection->api_token, $connection->api_secret);

        $connection->load('accounts');

        $transactionsPerAccount = [];

        foreach ($connection->accounts as $account) {
            $count = $this->transactionSync->sync($account, $client, $dateFrom, $dateTo);
            $this->balanceSync->sync($account, $client);
            $transactionsPerAccount[$account->name] = $count;
        }

        return [
            'transactions_synced' => array_sum($transactionsPerAccount),
            'transactions_per_account' => $transactionsPerAccount,
        ];
    }
}
