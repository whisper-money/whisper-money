<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\CoinbaseBalanceSyncService;
use App\Services\Banking\CoinbaseClient;

class CoinbaseSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private CoinbaseBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $client = new CoinbaseClient($connection->api_token, $connection->api_secret);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $this->balanceSync->sync($account, $client, $isFirstSync, backfillMissingHistory: true);
        }

        return [];
    }
}
