<?php

use App\Contracts\BankingConnectionSyncer;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use App\Services\Banking\Sync\BankingConnectionSyncerFactory;
use App\Services\Banking\Sync\BinanceSyncer;
use App\Services\Banking\Sync\BitpandaSyncer;
use App\Services\Banking\Sync\CoinbaseSyncer;
use App\Services\Banking\Sync\EnableBankingSyncer;
use App\Services\Banking\Sync\IndexaCapitalSyncer;
use App\Services\Banking\Sync\InteractiveBrokersSyncer;
use App\Services\Banking\Sync\WiseSyncer;

dataset('providers', [
    'indexacapital' => [BankingProvider::IndexaCapital, IndexaCapitalSyncer::class],
    'binance' => [BankingProvider::Binance, BinanceSyncer::class],
    'wise' => [BankingProvider::Wise, WiseSyncer::class],
    'bitpanda' => [BankingProvider::Bitpanda, BitpandaSyncer::class],
    'coinbase' => [BankingProvider::Coinbase, CoinbaseSyncer::class],
    'interactivebrokers' => [BankingProvider::InteractiveBrokers, InteractiveBrokersSyncer::class],
    'enablebanking' => [BankingProvider::EnableBanking, EnableBankingSyncer::class],
]);

it('resolves the matching syncer for each provider', function (BankingProvider $provider, string $expected) {
    $connection = new BankingConnection(['provider' => $provider]);

    expect(app(BankingConnectionSyncerFactory::class)->make($connection))->toBeInstanceOf($expected);
})->with('providers');

it('covers every provider enum case', function () {
    foreach (BankingProvider::cases() as $provider) {
        $connection = new BankingConnection(['provider' => $provider]);

        expect(app(BankingConnectionSyncerFactory::class)->make($connection))
            ->toBeInstanceOf(BankingConnectionSyncer::class);
    }
});

it('only lets consent-based providers expire', function () {
    expect(app(EnableBankingSyncer::class)->expires())->toBeTrue()
        ->and(app(BinanceSyncer::class)->expires())->toBeFalse()
        ->and(app(WiseSyncer::class)->expires())->toBeFalse();
});

it('notifies on auth failure for every API-key provider but not EnableBanking', function () {
    expect(app(IndexaCapitalSyncer::class)->notifiesOnAuthFailure())->toBeTrue()
        ->and(app(BinanceSyncer::class)->notifiesOnAuthFailure())->toBeTrue()
        ->and(app(BitpandaSyncer::class)->notifiesOnAuthFailure())->toBeTrue()
        ->and(app(CoinbaseSyncer::class)->notifiesOnAuthFailure())->toBeTrue()
        ->and(app(InteractiveBrokersSyncer::class)->notifiesOnAuthFailure())->toBeTrue()
        ->and(app(InteractiveBrokersSyncer::class)->expires())->toBeFalse()
        ->and(app(WiseSyncer::class)->notifiesOnAuthFailure())->toBeTrue()
        ->and(app(EnableBankingSyncer::class)->notifiesOnAuthFailure())->toBeFalse();
});
