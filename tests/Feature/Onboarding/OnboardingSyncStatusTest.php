<?php

use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;

it('returns pending false when user has no banking connections', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('returns pending false when all banking connections have been synced', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('returns pending true when an active connection has not been synced yet', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => true]);
});

it('returns pending false when unsynced connection has an error status', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->error()->create([
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('reports a rate limited connection as failed instead of pending', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->rateLimited()->create();

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false, 'failed' => true]);
});

it('keeps waiting while a healthy connection syncs alongside a failed one', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->rateLimited()->create();
    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => true, 'failed' => false]);
});

it('does not report a synced connection as failed', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false, 'failed' => false]);
});

it('returns pending false when unsynced connection is revoked', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->revoked()->create([
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

it('requires authentication', function () {
    $this->getJson('/onboarding/sync-status')
        ->assertUnauthorized();
});

it('only considers the authenticated users connections', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $other = User::factory()->create(['onboarded_at' => null]);

    // Other user has a pending sync — should not affect our user
    BankingConnection::factory()->for($other)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson(['pending' => false]);
});

// The counters are what let the syncing screen be a progress report rather than
// a spinner, so they have to count only what the bank has actually handed over.
it('counts what the bank has handed over so far', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $connection = BankingConnection::factory()->for($user)->create([
        'aspsp_name' => 'BBVA',
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $connected = Account::factory()->count(2)->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    // Two named counterparties across three transactions, spanning three months.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $connected->first()->id,
        'creditor_name' => 'Mercadona',
        'transaction_date' => '2026-01-10',
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $connected->first()->id,
        'creditor_name' => 'Mercadona',
        'transaction_date' => '2026-02-11',
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $connected->last()->id,
        'creditor_name' => 'Repsol',
        'transaction_date' => '2026-03-12',
    ]);

    // A hand-made account is not the bank's doing, so it stays out of the count.
    $manual = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => null,
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $manual->id,
        'creditor_name' => 'Imported by hand',
        'transaction_date' => '2020-01-01',
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson([
            'pending' => true,
            'bank' => 'BBVA',
            'progress' => [
                'transactions' => 3,
                'merchants' => 2,
                'accounts' => 2,
                'months' => 3,
                'first_date' => '2026-01-01',
                'last_date' => '2026-03-01',
            ],
        ]);
});

it('reports empty counters before anything has arrived', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson([
            'progress' => [
                'transactions' => 0,
                'merchants' => 0,
                'accounts' => 0,
                'months' => 0,
                'first_date' => null,
                'last_date' => null,
            ],
        ]);
});
