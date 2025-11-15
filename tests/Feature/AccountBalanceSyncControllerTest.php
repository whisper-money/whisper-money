<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;

it('can fetch user account balances', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balances = AccountBalance::factory()->count(3)->for($account)->create();

    $response = $this->actingAs($user)->getJson('/api/sync/account-balances');

    $response->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'account_id',
                    'balance_date',
                    'balance',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
});

it('only returns user own account balances', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    AccountBalance::factory()->for($account)->create();
    AccountBalance::factory()->for($otherAccount)->create();

    $response = $this->actingAs($user)->getJson('/api/sync/account-balances');

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

it('can filter balances by updated_at', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $oldBalance = AccountBalance::factory()->for($account)->create([
        'updated_at' => now()->subDays(2),
    ]);

    $newBalance = AccountBalance::factory()->for($account)->create([
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/sync/account-balances?since='.now()->subDay()->toISOString());

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newBalance->id);
});

it('can create an account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $balanceData = [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 134566,
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'account_id',
                'balance_date',
                'balance',
                'created_at',
                'updated_at',
            ],
        ]);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 134566,
    ]);
});

it('can create an account balance with an ID', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $id = (string) \Illuminate\Support\Str::uuid7();

    $balanceData = [
        'id' => $id,
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 250000,
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertCreated()
        ->assertJsonPath('data.id', $id);

    $this->assertDatabaseHas('account_balances', [
        'id' => $id,
        'account_id' => $account->id,
    ]);
});

it('validates required fields when creating a balance', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id', 'balance_date', 'balance']);
});

it('can update an account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balance = AccountBalance::factory()->for($account)->create([
        'balance' => 100000,
    ]);

    $updateData = [
        'account_id' => $account->id,
        'balance_date' => $balance->balance_date->toDateString(),
        'balance' => 200000,
    ];

    $response = $this->actingAs($user)->patchJson("/api/sync/account-balances/{$balance->id}", $updateData);

    $response->assertSuccessful()
        ->assertJsonPath('data.balance', 200000);

    $this->assertDatabaseHas('account_balances', [
        'id' => $balance->id,
        'balance' => 200000,
    ]);
});

it('cannot update another user account balance', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();
    $balance = AccountBalance::factory()->for($account)->create();

    $updateData = [
        'account_id' => $account->id,
        'balance_date' => $balance->balance_date->toDateString(),
        'balance' => 999999,
    ];

    $response = $this->actingAs($user)->patchJson("/api/sync/account-balances/{$balance->id}", $updateData);

    $response->assertForbidden();
});

it('enforces unique constraint on account_id and balance_date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->toDateString();

    AccountBalance::factory()->for($account)->create([
        'balance_date' => $date,
        'balance' => 100000,
    ]);

    $balanceData = [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => 200000,
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertStatus(500);
});
