<?php

use App\Jobs\SyncAllBankingConnectionsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

test('banking:sync dispatches job for all connections when no filters provided', function () {
    Queue::fake();

    artisan('banking:sync')
        ->expectsOutputToContain('Banking sync jobs dispatched for all active connections.')
        ->assertSuccessful();

    Queue::assertPushed(SyncAllBankingConnectionsJob::class);
});

test('banking:sync filters by user email', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);
    $connection = BankingConnection::factory()->for($user)->create();

    // Another user's connection that should NOT be synced
    BankingConnection::factory()->create();

    artisan('banking:sync', ['--user' => 'test@example.com'])
        ->expectsOutputToContain('Banking sync jobs dispatched for 1 connection(s).')
        ->assertSuccessful();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
    Queue::assertPushed(SyncBankingConnectionJob::class, function ($job) use ($connection) {
        return $job->bankingConnection->id === $connection->id;
    });
    Queue::assertNotPushed(SyncAllBankingConnectionsJob::class);
});

test('banking:sync filters by connection ID', function () {
    Queue::fake();

    $connection = BankingConnection::factory()->create();
    BankingConnection::factory()->create();

    artisan('banking:sync', ['--connection' => $connection->id])
        ->expectsOutputToContain('Banking sync jobs dispatched for 1 connection(s).')
        ->assertSuccessful();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
    Queue::assertPushed(SyncBankingConnectionJob::class, function ($job) use ($connection) {
        return $job->bankingConnection->id === $connection->id;
    });
});

test('banking:sync fails when user email is not found', function () {
    artisan('banking:sync', ['--user' => 'nonexistent@example.com'])
        ->expectsOutputToContain("User with email 'nonexistent@example.com' not found.")
        ->assertFailed();
});

test('banking:sync warns when no active connections match filters', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);
    BankingConnection::factory()->expired()->for($user)->create();

    artisan('banking:sync', ['--user' => 'test@example.com'])
        ->expectsOutputToContain('No active banking connections found matching the given filters.')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncBankingConnectionJob::class);
});

test('banking:sync skips expired connections when filtering by user', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);
    BankingConnection::factory()->for($user)->create();
    BankingConnection::factory()->expired()->for($user)->create();

    artisan('banking:sync', ['--user' => 'test@example.com'])
        ->expectsOutputToContain('Banking sync jobs dispatched for 1 connection(s).')
        ->assertSuccessful();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
});

test('banking:sync can combine user and connection filters', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);
    $connection = BankingConnection::factory()->for($user)->create();
    BankingConnection::factory()->for($user)->create();

    artisan('banking:sync', ['--user' => 'test@example.com', '--connection' => $connection->id])
        ->expectsOutputToContain('Banking sync jobs dispatched for 1 connection(s).')
        ->assertSuccessful();

    Queue::assertPushed(SyncBankingConnectionJob::class, 1);
    Queue::assertPushed(SyncBankingConnectionJob::class, function ($job) use ($connection) {
        return $job->bankingConnection->id === $connection->id;
    });
});

test('banking:sync passes fullSync flag when --full is provided', function () {
    Queue::fake();

    artisan('banking:sync', ['--full' => true])
        ->expectsOutputToContain('Banking sync jobs dispatched for all active connections.')
        ->assertSuccessful();

    Queue::assertPushed(SyncAllBankingConnectionsJob::class, function ($job) {
        return $job->fullSync === true;
    });
});

test('banking:sync passes fullSync to individual connections with --full', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'test@example.com']);
    BankingConnection::factory()->for($user)->create();

    artisan('banking:sync', ['--user' => 'test@example.com', '--full' => true])
        ->assertSuccessful();

    Queue::assertPushed(SyncBankingConnectionJob::class, function ($job) {
        return $job->fullSync === true;
    });
});

test('banking:sync does not set fullSync by default', function () {
    Queue::fake();

    artisan('banking:sync')
        ->assertSuccessful();

    Queue::assertPushed(SyncAllBankingConnectionsJob::class, function ($job) {
        return $job->fullSync === false;
    });
});

test('banking:sync --sync fails for auth errors instead of reporting success', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);
    $connection = BankingConnection::factory()->indexaCapital()->for($user)->create([
        'last_synced_at' => now()->subDay(),
    ]);

    $user->accounts()->create([
        'name' => 'Indexa Capital Account',
        'name_iv' => null,
        'encrypted' => false,
        'bank_id' => null,
        'currency_code' => 'EUR',
        'type' => 'investment',
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    expect(fn () => artisan('banking:sync', [
        '--user' => 'test@example.com',
        '--connection' => $connection->id,
        '--sync' => true,
    ]))->toThrow(RequestException::class);
});
