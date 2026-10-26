<?php

use App\Enums\BankingConnectionStatus;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;

it('tells the syncing screen whether to keep waiting', function (Closure $connections, array $expected) {
    $user = User::factory()->create(['onboarded_at' => null]);

    $connections($user);

    $this->actingAs($user)
        ->getJson('/onboarding/sync-status')
        ->assertOk()
        ->assertJson($expected);
})->with([
    'no banking connections' => [fn () => null, ['pending' => false]],
    'every connection synced, none of them failed' => [fn (User $user) => BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now(),
    ]), ['pending' => false, 'failed' => false]],
    'an active connection not synced yet' => [fn (User $user) => BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
    ]), ['pending' => true]],
    'an unsynced connection in error' => [fn (User $user) => BankingConnection::factory()->for($user)->error()->create([
        'last_synced_at' => null,
    ]), ['pending' => false]],
    'a rate limited connection, failed instead of pending' => [fn (User $user) => BankingConnection::factory()->for($user)->rateLimited()->create(), ['pending' => false, 'failed' => true]],
    'a healthy connection syncing alongside a failed one' => [function (User $user) {
        BankingConnection::factory()->for($user)->rateLimited()->create();
        BankingConnection::factory()->for($user)->create([
            'status' => BankingConnectionStatus::Active,
            'last_synced_at' => null,
        ]);
    }, ['pending' => true, 'failed' => false]],
    'an unsynced connection that was revoked' => [fn (User $user) => BankingConnection::factory()->for($user)->revoked()->create([
        'last_synced_at' => null,
    ]), ['pending' => false]],
]);

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
