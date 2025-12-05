<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;

it('can update current balance for an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $balanceData = [
        'balance' => 134566,
    ];

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", $balanceData);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'id',
                'account_id',
                'balance_date',
                'balance',
                'created_at',
                'updated_at',
            ],
        ])
        ->assertJsonPath('data.account_id', $account->id)
        ->assertJsonPath('data.balance', 134566);

    expect($response->json('data.balance_date'))->toContain(now()->toDateString());

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 134566,
    ]);
});

it('updates existing balance for current date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    AccountBalance::factory()->for($account)->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    $balanceData = [
        'balance' => 200000,
    ];

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", $balanceData);

    $response->assertSuccessful()
        ->assertJsonPath('data.balance', 200000);

    $this->assertDatabaseCount('account_balances', 1);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 200000,
    ]);
});

it('cannot update balance for another user account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();

    $balanceData = [
        'balance' => 999999,
    ];

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", $balanceData);

    $response->assertForbidden();

    $this->assertDatabaseMissing('account_balances', [
        'account_id' => $account->id,
        'balance' => 999999,
    ]);
});

it('validates required balance field', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['balance']);
});

it('validates balance is an integer', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", [
        'balance' => 'not-a-number',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['balance']);
});

it('can list balances for an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    AccountBalance::factory()->for($account)->count(3)->create();

    $response = $this->actingAs($user)->getJson("/api/accounts/{$account->id}/balances");

    $response->assertSuccessful()
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
            'current_page',
            'last_page',
            'per_page',
            'total',
        ])
        ->assertJsonPath('total', 3)
        ->assertJsonPath('per_page', 50);
});

it('cannot list balances for another user account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();

    AccountBalance::factory()->for($account)->count(3)->create();

    $response = $this->actingAs($user)->getJson("/api/accounts/{$account->id}/balances");

    $response->assertForbidden();
});

it('can delete a balance record', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $balance = AccountBalance::factory()->for($account)->create();

    $response = $this->actingAs($user)->deleteJson("/api/accounts/{$account->id}/balances/{$balance->id}");

    $response->assertNoContent();

    $this->assertDatabaseMissing('account_balances', [
        'id' => $balance->id,
    ]);
});

it('cannot delete a balance for another user account', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();
    $balance = AccountBalance::factory()->for($account)->create();

    $response = $this->actingAs($user)->deleteJson("/api/accounts/{$account->id}/balances/{$balance->id}");

    $response->assertForbidden();

    $this->assertDatabaseHas('account_balances', [
        'id' => $balance->id,
    ]);
});

it('returns 404 when deleting balance from wrong account', function () {
    $user = User::factory()->create();
    $account1 = Account::factory()->for($user)->create();
    $account2 = Account::factory()->for($user)->create();
    $balance = AccountBalance::factory()->for($account1)->create();

    $response = $this->actingAs($user)->deleteJson("/api/accounts/{$account2->id}/balances/{$balance->id}");

    $response->assertNotFound();

    $this->assertDatabaseHas('account_balances', [
        'id' => $balance->id,
    ]);
});
