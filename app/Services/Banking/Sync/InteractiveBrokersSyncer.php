<?php

namespace App\Services\Banking\Sync;

use App\Models\BankingConnection;
use App\Services\Banking\InteractiveBrokersBalanceSyncService;
use App\Services\Banking\InteractiveBrokersClient;

class InteractiveBrokersSyncer extends AbstractBankingConnectionSyncer
{
    public function __construct(private InteractiveBrokersBalanceSyncService $balanceSync) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        // One Flex statement covers every account; fetch once to stay within
        // IB's per-query rate limit, then distribute to each account.
        $client = new InteractiveBrokersClient($connection->api_token, $connection->api_secret);
        $accounts = $client->fetchStatement();

        $connection->load('accounts');

        foreach ($connection->accounts as $account) {
            $this->balanceSync->sync($account, $accounts, $isFirstSync);
        }

        return [];
    }
}
