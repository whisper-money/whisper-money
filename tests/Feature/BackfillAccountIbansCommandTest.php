<?php

use App\Contracts\BankingProviderInterface;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
    ]);
});

test('backfills iban for enable banking accounts with missing iban', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-abc123',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->with('uid-abc123')
        ->andReturn(['uid' => 'uid-abc123', 'account_id' => ['iban' => 'ES1234567890'], 'currency' => 'EUR', 'name' => 'Test']);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans')->assertSuccessful();

    expect($account->fresh()->iban)->toBe('ES1234567890');
});

test('skips accounts where api returns no iban', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-no-iban',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->with('uid-no-iban')
        ->andReturn(['uid' => 'uid-no-iban', 'account_id' => [], 'currency' => 'EUR', 'name' => null]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans')
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped (no IBAN in API response): 1');

    expect($account->fresh()->iban)->toBeNull();
});

test('does not touch accounts that already have an iban', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-already',
        'iban' => 'ES9999',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('getAccount');

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans')
        ->assertSuccessful()
        ->expectsOutputToContain('No accounts found with missing IBAN');
});

test('does not touch accounts from non-enablebanking providers', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->indexaCapital()->create();
    Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-indexa',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('getAccount');

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans')
        ->assertSuccessful()
        ->expectsOutputToContain('No accounts found with missing IBAN');
});

test('dry run does not persist changes', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-dry',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->andReturn(['uid' => 'uid-dry', 'account_id' => ['iban' => 'ES5555'], 'currency' => 'EUR', 'name' => null]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('DRY RUN');

    expect($account->fresh()->iban)->toBeNull();
});

test('filters by user email', function () {
    $targetUser = User::factory()->create(['email' => 'target@example.com']);
    $otherUser = User::factory()->create(['email' => 'other@example.com']);

    $connection = BankingConnection::factory()->for($targetUser)->create(['provider' => 'enablebanking']);
    $otherConnection = BankingConnection::factory()->for($otherUser)->create(['provider' => 'enablebanking']);

    Account::factory()->for($targetUser)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-target',
        'iban' => null,
    ]);
    Account::factory()->for($otherUser)->create([
        'banking_connection_id' => $otherConnection->id,
        'external_account_id' => 'uid-other',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->with('uid-target')
        ->andReturn(['uid' => 'uid-target', 'account_id' => ['iban' => 'ES1111'], 'currency' => 'EUR', 'name' => null]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans', ['--user' => 'target@example.com'])->assertSuccessful();
});

test('returns failure when user email is not found', function () {
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans', ['--user' => 'unknown@example.com'])
        ->assertFailed()
        ->expectsOutputToContain("User with email 'unknown@example.com' not found");
});

test('filters by connection id', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    $otherConnection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);

    Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-conn-a',
        'iban' => null,
    ]);
    Account::factory()->for($user)->create([
        'banking_connection_id' => $otherConnection->id,
        'external_account_id' => 'uid-conn-b',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->with('uid-conn-a')
        ->andReturn(['uid' => 'uid-conn-a', 'account_id' => ['iban' => 'ES2222'], 'currency' => 'EUR', 'name' => null]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans', ['--connection' => $connection->id])->assertSuccessful();
});

test('treats 404 as expired session and does not count as failure', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-expired',
        'iban' => null,
    ]);

    $exception = new RequestException(new HttpClientResponse(new GuzzleResponse(404)));

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->andThrow($exception);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans')
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped (expired/revoked session): 1');

    expect($account->fresh()->iban)->toBeNull();
});

test('continues and reports failure when api call throws a non-404 error', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->for($user)->create(['provider' => 'enablebanking']);
    $account = Account::factory()->for($user)->create([
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'uid-fail',
        'iban' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getAccount')
        ->once()
        ->andThrow(new \RuntimeException('API unavailable'));

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->artisan('banking:backfill-ibans')
        ->assertFailed()
        ->expectsOutputToContain('Failed: 1');

    expect($account->fresh()->iban)->toBeNull();
});
