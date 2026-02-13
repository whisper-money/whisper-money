<?php

use App\Jobs\SyncAllBankingConnectionsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
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
