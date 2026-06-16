<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\IndexaCapitalBalanceSyncService;
use App\Services\Banking\IndexaCapitalClient;

class IndexaCapitalSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private IndexaCapitalBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $client = new IndexaCapitalClient($connection->api_token);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $this->balanceSync->sync($account, $client, $isFirstSync);
        }

        return [];
    }
}
