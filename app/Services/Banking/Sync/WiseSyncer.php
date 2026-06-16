<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\WiseBalanceSyncService;
use App\Services\Banking\WiseClient;
use App\Services\Banking\WiseTransactionSyncService;

class WiseSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(
        private WiseTransactionSyncService $transactionSync,
        private WiseBalanceSyncService $balanceSync,
    ) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $dateFrom = $isFirstSync
            ? now()->subYear()->toDateString()
            : ($connection->last_synced_at?->toDateString() ?? now()->subMonth()->toDateString());
        $dateTo = now()->toDateString();

        $client = new WiseClient($connection->api_token);

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
