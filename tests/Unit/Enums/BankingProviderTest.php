<?php

use App\Enums\AccountType;
use App\Enums\BankingProvider;

it('uses an API key for every provider but EnableBanking', function (BankingProvider $provider, bool $usesApiKey) {
    expect($provider->usesApiKey())->toBe($usesApiKey);
})->with([
    'indexa capital' => [BankingProvider::IndexaCapital, true],
    'binance' => [BankingProvider::Binance, true],
    'bitpanda' => [BankingProvider::Bitpanda, true],
    'kraken' => [BankingProvider::Kraken, true],
    'coinbase' => [BankingProvider::Coinbase, true],
    'interactive brokers' => [BankingProvider::InteractiveBrokers, true],
    'wise' => [BankingProvider::Wise, true],
    'enable banking' => [BankingProvider::EnableBanking, false],
]);

it('defaults investment providers to an investment account and cash providers to a checking one', function (BankingProvider $provider, AccountType $accountType) {
    expect($provider->defaultAccountType())->toBe($accountType);
})->with([
    'indexa capital' => [BankingProvider::IndexaCapital, AccountType::Investment],
    'binance' => [BankingProvider::Binance, AccountType::Investment],
    'bitpanda' => [BankingProvider::Bitpanda, AccountType::Investment],
    'kraken' => [BankingProvider::Kraken, AccountType::Investment],
    'coinbase' => [BankingProvider::Coinbase, AccountType::Investment],
    'interactive brokers' => [BankingProvider::InteractiveBrokers, AccountType::Investment],
    'wise' => [BankingProvider::Wise, AccountType::Checking],
    'enable banking' => [BankingProvider::EnableBanking, AccountType::Checking],
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

    expect(BankingProvider::Kraken->credentialColumns([
        'api_key' => 'key',
        'api_secret' => 'secret',
        'country' => 'ES',
    ]))->toBe([
        'api_token' => 'key',
        'api_secret' => 'secret',
    ]);

    expect(BankingProvider::EnableBanking->credentialColumns([]))->toBe([]);
});

it('defines credential fields for every API-key provider', function () {
    foreach (BankingProvider::cases() as $provider) {
        if (! $provider->usesApiKey()) {
            continue;
        }

        // Every API-key provider must declare its credential shape; this is
        // what drives connect/update rules, the column mapping and the update
        // dialog — so a new provider can't be added without an update path.
        expect($provider->credentialFields())->not->toBeEmpty();
    }
});
