<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\BinanceBalanceSyncService;
use App\Services\Banking\BinanceClient;
use App\Services\Banking\BitpandaBalanceSyncService;
use App\Services\Banking\BitpandaClient;
use App\Services\Banking\CoinbaseBalanceSyncService;
use App\Services\Banking\CoinbaseClient;
use App\Services\Banking\IndexaCapitalBalanceSyncService;
use App\Services\Banking\IndexaCapitalClient;
use App\Services\Banking\InteractiveBrokersBalanceSyncService;
use App\Services\Banking\WiseBalanceSyncService;
use App\Services\Banking\WiseClient;
use Illuminate\Support\Facades\Http;

/**
 * Each provider's balance sync run against an account, with a client that
 * fails the test if the provider is asked for anything.
 */
dataset('balance syncs', [
    'binance' => [function (Account $account) {
        $client = Mockery::mock(BinanceClient::class);
        $client->shouldNotReceive('getAccount');

        app(BinanceBalanceSyncService::class)->sync($account, $client);
    }],
    'bitpanda' => [function (Account $account) {
        $client = Mockery::mock(BitpandaClient::class);
        $client->shouldNotReceive('getTickerPrices');
        $client->shouldNotReceive('getCryptoWallets');

        app(BitpandaBalanceSyncService::class)->sync($account, $client);
    }],
    'coinbase' => [function (Account $account) {
        app(CoinbaseBalanceSyncService::class)->sync($account, new CoinbaseClient('organizations/org/apiKeys/key', testEcPrivateKey()));
    }],
    'indexa capital' => [function (Account $account) {
        $client = Mockery::mock(IndexaCapitalClient::class);
        $client->shouldNotReceive('getPerformance');

        app(IndexaCapitalBalanceSyncService::class)->sync($account, $client);
    }],
    'interactive brokers' => [function (Account $account) {
        app(InteractiveBrokersBalanceSyncService::class)->sync($account, []);
    }],
    'wise' => [function (Account $account) {
        app(WiseBalanceSyncService::class)->sync($account, new WiseClient('test-token'));
    }],
]);

test('skips an account without an external_account_id', function (Closure $sync) {
    Http::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => null,
        'currency_code' => 'EUR',
    ]);

    $sync($account);

    expect($account->balances()->count())->toBe(0);
    Http::assertNothingSent();
})->with('balance syncs');
