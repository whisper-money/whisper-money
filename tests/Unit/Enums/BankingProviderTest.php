<?php

use App\Enums\AccountType;
use App\Enums\BankingProvider;

it('uses an API key for non-EnableBanking providers', function (BankingProvider $provider) {
    expect($provider->usesApiKey())->toBeTrue();
})->with([
    'indexa capital' => BankingProvider::IndexaCapital,
    'binance' => BankingProvider::Binance,
    'bitpanda' => BankingProvider::Bitpanda,
    'coinbase' => BankingProvider::Coinbase,
    'interactive brokers' => BankingProvider::InteractiveBrokers,
    'wise' => BankingProvider::Wise,
]);

it('does not use an API key for EnableBanking', function () {
    expect(BankingProvider::EnableBanking->usesApiKey())->toBeFalse();
});

it('defaults investment providers to an investment account', function (BankingProvider $provider) {
    expect($provider->defaultAccountType())->toBe(AccountType::Investment);
})->with([
    'indexa capital' => BankingProvider::IndexaCapital,
    'binance' => BankingProvider::Binance,
    'bitpanda' => BankingProvider::Bitpanda,
    'coinbase' => BankingProvider::Coinbase,
    'interactive brokers' => BankingProvider::InteractiveBrokers,
]);

it('defaults cash providers to a checking account', function (BankingProvider $provider) {
    expect($provider->defaultAccountType())->toBe(AccountType::Checking);
})->with([
    'wise' => BankingProvider::Wise,
    'enable banking' => BankingProvider::EnableBanking,
]);

it('maps credential inputs onto the encrypted connection columns', function () {
    expect(BankingProvider::Binance->credentialColumns([
        'api_key' => 'key',
        'api_secret' => 'secret',
        'country' => 'ES',
    ]))->toBe([
        'api_token' => 'key',
        'api_secret' => 'secret',
    ]);

    expect(BankingProvider::InteractiveBrokers->credentialColumns([
        'token' => 'flex-token',
        'query_id' => '123456',
    ]))->toBe([
        'api_token' => 'flex-token',
        'api_secret' => '123456',
    ]);

    expect(BankingProvider::EnableBanking->credentialColumns([]))->toBe([]);
});
