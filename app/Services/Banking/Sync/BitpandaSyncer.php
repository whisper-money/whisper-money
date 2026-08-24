<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\BitpandaBalanceSyncService;
use App\Services\Banking\BitpandaClient;

class BitpandaSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private BitpandaBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $client = new BitpandaClient($connection->api_token);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $this->balanceSync->sync($account, $client);
        }

        return [];
    }
}
