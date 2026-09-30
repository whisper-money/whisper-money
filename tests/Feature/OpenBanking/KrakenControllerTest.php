<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function krakenCredentials(array $overrides = []): array
{
    return [
        'api_key' => 'valid-kraken-api-key-12345',
        'api_secret' => base64_encode('valid-kraken-api-secret'),
        'country' => 'ES',
        ...$overrides,
    ];
}

function fakeKrakenPermissions(array $balanceErrors = [], array $ledgerErrors = []): void
{
    Http::fake([
        'api.kraken.com/0/private/Balance' => Http::response(['error' => $balanceErrors, 'result' => ['XXBT' => '0.5']]),
        'api.kraken.com/0/private/Ledgers' => Http::response(['error' => $ledgerErrors, 'result' => ['ledger' => [], 'count' => 0]]),
    ]);
}

test('users can connect a kraken account with a key that can query funds and ledger', function () {
    Queue::fake();
    fakeKrakenPermissions();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);

    $this->actingAs($user)->postJson('/open-banking/kraken/connect', krakenCredentials())
        ->assertOk()
        ->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'kraken')->sole();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping)
        ->and($connection->api_token)->toBe('valid-kraken-api-key-12345')
        ->and($connection->api_secret)->toBe(base64_encode('valid-kraken-api-secret'))
        ->and($connection->aspsp_logo)->toBe('/images/banks/logos/kraken.png')
        ->and($connection->pending_accounts_data)->toEqual([
            ['uid' => 'kraken-portfolio', 'currency' => 'EUR', 'name' => 'Crypto Portfolio'],
        ]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/0/private/Balance'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/0/private/Ledgers'));
    Queue::assertNothingPushed();
});

test('a kraken key without a required permission is rejected naming it', function (array $balanceErrors, array $ledgerErrors, string $permission) {
    fakeKrakenPermissions($balanceErrors, $ledgerErrors);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/open-banking/kraken/connect', krakenCredentials())
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => "Your Kraken API key is missing the \"{$permission}\" permission. Enable it and try again."]);

    $this->assertDatabaseMissing('banking_connections', ['user_id' => $user->id, 'provider' => 'kraken']);
})->with([
    'query funds' => [['EGeneral:Permission denied'], [], 'Query Funds'],
    'query ledger entries' => [[], ['EGeneral:Permission denied'], 'Query Ledger Entries'],
]);

test('an invalid kraken key returns 422', function () {
    fakeKrakenPermissions(['EAPI:Invalid key']);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/open-banking/kraken/connect', krakenCredentials())
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Invalid API key or failed to connect to Kraken.']);

    $this->assertDatabaseMissing('banking_connections', ['user_id' => $user->id, 'provider' => 'kraken']);
});

test('kraken requires the api key, secret and country', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/open-banking/kraken/connect', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_key', 'api_secret', 'country']);
});

test('kraken requires authentication', function () {
    $this->postJson('/open-banking/kraken/connect', krakenCredentials())->assertUnauthorized();
});

test('free tier users cannot connect a kraken account after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/open-banking/kraken/connect', krakenCredentials())
        ->assertStatus(402)
        ->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', ['user_id' => $user->id, 'provider' => 'kraken']);
});

test('kraken auto-creates the portfolio account during onboarding', function () {
    config(['subscriptions.enabled' => true]);
    Queue::fake();
    fakeKrakenPermissions();

    $user = User::factory()->notOnboarded()->subscribed()->create(['currency_code' => 'EUR']);

    $this->actingAs($user)->postJson('/open-banking/kraken/connect', krakenCredentials())
        ->assertOk()
        ->assertJsonPath('redirect_url', route('onboarding', ['step' => 'create-account']));

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'kraken')->sole();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'kraken-portfolio',
        'type' => 'investment',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});
