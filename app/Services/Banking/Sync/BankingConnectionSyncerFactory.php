<?php

namespace App\Services\Banking\Sync;

use App\Contracts\BankingConnectionSyncer;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;

class BankingConnectionSyncerFactory
{
    public function make(BankingConnection $connection): BankingConnectionSyncer
    {
        $syncer = match ($connection->provider) {
            BankingProvider::IndexaCapital => IndexaCapitalSyncer::class,
            BankingProvider::Binance => BinanceSyncer::class,
            BankingProvider::Wise => WiseSyncer::class,
            BankingProvider::Bitpanda => BitpandaSyncer::class,
            BankingProvider::Coinbase => CoinbaseSyncer::class,
            BankingProvider::EnableBanking => EnableBankingSyncer::class,
        };

        return app($syncer);
    }
}
