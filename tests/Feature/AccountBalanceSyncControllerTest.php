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

it('updates existing balance when creating with duplicate account_id and balance_date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = '2025-01-15';

    $initialBalance = AccountBalance::factory()->for($account)->create([
        'balance_date' => $date,
        'balance' => 100000,
    ]);

    $balanceData = [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => 250000,
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertOk()
        ->assertJsonPath('data.id', $initialBalance->id)
        ->assertJsonPath('data.balance', 250000);

    expect(AccountBalance::where('account_id', $account->id)
        ->where('balance_date', $date)
        ->count())->toBe(1);
});

it('can update balance by adding transaction amount to existing balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->toDateString();

    // Create an initial balance
    $initialBalance = AccountBalance::factory()->for($account)->create([
        'balance_date' => $date,
        'balance' => 100000, // $1,000.00 in cents
    ]);

    // Simulate adding a transaction amount (e.g., +$50.00 = 5000 cents)
    $transactionAmount = 5000;
    $newBalance = $initialBalance->balance + $transactionAmount;

    $balanceData = [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => $newBalance, // 105000 cents = $1,050.00
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertOk()
        ->assertJsonPath('data.balance', 105000);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => 105000,
    ]);
});

it('can create new balance with transaction amount when no previous balance exists', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->toDateString();

    // Simulate starting from 0 and adding a transaction amount
    $transactionAmount = -7500; // expense of $75.00

    $balanceData = [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => $transactionAmount, // -$75.00
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertCreated()
        ->assertJsonPath('data.balance', -7500);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => -7500,
    ]);
});

it('can subtract expense amount from existing balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->toDateString();

    // Create an initial balance
    $initialBalance = AccountBalance::factory()->for($account)->create([
        'balance_date' => $date,
        'balance' => 500000, // $5,000.00 in cents
    ]);

    // Simulate adding an expense transaction (-$120.50 = -12050 cents)
    $transactionAmount = -12050;
    $newBalance = $initialBalance->balance + $transactionAmount;

    $balanceData = [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => $newBalance, // 487950 cents = $4,879.50
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/account-balances', $balanceData);

    $response->assertOk()
        ->assertJsonPath('data.balance', 487950);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => $date,
        'balance' => 487950,
    ]);
});
