<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\KrakenBalanceSyncService;
use App\Services\Banking\KrakenClient;

class KrakenSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private KrakenBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $client = new KrakenClient($connection->api_token, $connection->api_secret);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $this->balanceSync->sync($connection, $account, $client, $isFirstSync);
        }

        return [];
    }
}
