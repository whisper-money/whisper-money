<?php

namespace App\Services\Banking\Sync;

use App\Jobs\SyncBinanceHistoricalBalancesJob;
use App\Models\BankingConnection;
use App\Services\Banking\BinanceBalanceSyncService;
use App\Services\Banking\BinanceClient;

class BinanceSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private BinanceBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $client = new BinanceClient($connection->api_token, $connection->api_secret);

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            if ($isFirstSync) {
                $this->balanceSync->syncCurrentBalance($account, $client);
                SyncBinanceHistoricalBalancesJob::dispatch($account)->delay(now()->addSeconds(30));
            } else {
                $this->balanceSync->sync($account, $client, isFirstSync: false);
            }
        }

        return [];
    }
}
