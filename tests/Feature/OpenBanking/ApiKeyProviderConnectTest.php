<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * The guards every API-key provider inherits from OpenBankingConnectController:
 * guests are turned away, free-tier users are sent to subscribe, and credentials
 * the provider rejects never create a connection. Each provider's own file keeps
 * what differs between them (pending accounts, validation rules, onboarding).
 */

/**
 * Each provider's connect route, the `provider` column its connections get, a
 * credentials payload that passes validation, the HTTP responses that make the
 * provider reject those credentials, and the message the user then reads.
 */
dataset('api key providers', [
    'binance' => [
        'binance',
        'binance',
        fn () => ['api_key' => 'valid-test-api-key-12345', 'api_secret' => 'valid-test-api-secret-12345', 'country' => 'ES'],
        fn () => ['api.binance.com/api/v3/account*' => Http::response(['msg' => 'Invalid API-key'], 401)],
        'Invalid API credentials or failed to connect to Binance.',
    ],
    'bitpanda' => [
        'bitpanda',
        'bitpanda',
        fn () => ['api_key' => 'valid-test-api-key-12345', 'country' => 'ES'],
        fn () => ['api.bitpanda.com/v1/wallets' => Http::response(['error' => 'Unauthorized'], 401)],
        'Invalid API key or failed to connect to Bitpanda.',
    ],
    'coinbase' => [
        'coinbase',
        'coinbase',
        fn () => ['api_key_name' => 'organizations/org-uuid/apiKeys/key-uuid', 'private_key' => testEcPrivateKey(), 'country' => 'ES'],
        fn () => ['api.coinbase.com/api/v3/brokerage/accounts*' => Http::response(['error' => 'unauthorized'], 401)],
        'Invalid API credentials or failed to connect to Coinbase. Use a view-only key with "Opt-out of IP allowlisting" ticked.',
    ],
    'indexa capital' => [
        'indexa-capital',
        'indexacapital',
        fn () => ['api_token' => 'valid-test-token-12345'],
        fn () => ['api.indexacapital.com/users/me' => Http::response(['message' => 'Unauthorized'], 401)],
        'Invalid API token or failed to connect to Indexa Capital.',
    ],
    'interactive brokers' => [
        'interactive-brokers',
        'interactivebrokers',
        fn () => ['token' => 'flex-token-1234567890', 'query_id' => '123456'],
        fn () => ['*SendRequest*' => Http::response('<FlexStatementResponse><Status>Fail</Status><ErrorCode>1015</ErrorCode><ErrorMessage>Invalid token.</ErrorMessage></FlexStatementResponse>')],
        'Invalid Flex token or query ID, or failed to connect to Interactive Brokers.',
    ],
    'kraken' => [
        'kraken',
        'kraken',
        fn () => ['api_key' => 'valid-kraken-api-key-12345', 'api_secret' => base64_encode('valid-kraken-api-secret'), 'country' => 'ES'],
        fn () => [
            'api.kraken.com/0/private/Balance' => Http::response(['error' => ['EAPI:Invalid key'], 'result' => ['XXBT' => '0.5']]),
            'api.kraken.com/0/private/Ledgers' => Http::response(['error' => [], 'result' => ['ledger' => [], 'count' => 0]]),
        ],
        'Invalid API key or failed to connect to Kraken.',
    ],
    'wise' => [
        'wise',
        'wise',
        fn () => ['api_token' => 'invalid-wise-token-12345'],
        fn () => ['api.wise.com/v1/profiles' => Http::response(['error' => 'unauthorized'], 401)],
        'Invalid API token or failed to connect to Wise.',
    ],
]);

test('connecting requires authentication', function (string $route, string $provider, Closure $credentials, Closure $rejection, string $message) {
    $this->postJson("/open-banking/{$route}/connect", $credentials())->assertUnauthorized();
})->with('api key providers');

test('free tier users cannot connect after onboarding when subscriptions are enabled', function (string $route, string $provider, Closure $credentials, Closure $rejection, string $message) {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson("/open-banking/{$route}/connect", $credentials())
        ->assertPaymentRequired()
        ->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', ['user_id' => $user->id, 'provider' => $provider]);
})->with('api key providers');

test('credentials the provider rejects return 422 without creating a connection', function (string $route, string $provider, Closure $credentials, Closure $rejection, string $message) {
    Http::fake($rejection());

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson("/open-banking/{$route}/connect", $credentials())
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => $message]);

    $this->assertDatabaseMissing('banking_connections', ['user_id' => $user->id, 'provider' => $provider]);
})->with('api key providers');
