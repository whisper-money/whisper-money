<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;

it('can update current balance for an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

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
    $account = Account::factory()->withBalance()->for($user)->create();

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
    $account = Account::factory()->withBalance()->for($otherUser)->create();

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

it('validates the balance payload', function (string $method, string $path, array $payload, string $invalidField) {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    $response = $this->actingAs($user)->json($method, "/api/accounts/{$account->id}/{$path}", $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([$invalidField]);
})->with([
    'update current without a balance' => ['PUT', 'balance/current', [], 'balance'],
    'update current with a non-integer balance' => ['PUT', 'balance/current', ['balance' => 'not-a-number'], 'balance'],
    'update current with a non-integer invested amount' => ['PUT', 'balance/current', ['balance' => 500000, 'invested_amount' => 'not-a-number'], 'invested_amount'],
    'store with a non-integer balance' => ['POST', 'balances', fn () => ['balance' => 'not-a-number', 'balance_date' => now()->subDays(5)->toDateString()], 'balance'],
    'store with a decimal balance' => ['POST', 'balances', fn () => ['balance' => 123.45, 'balance_date' => now()->subDays(5)->toDateString()], 'balance'],
    'store with a decimal invested amount' => ['POST', 'balances', fn () => ['balance' => 300000, 'balance_date' => now()->subDays(5)->toDateString(), 'invested_amount' => 250.99], 'invested_amount'],
]);

it('can list balances for an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

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
    $account = Account::factory()->withBalance()->for($otherUser)->create();

    AccountBalance::factory()->for($account)->count(3)->create();

    $response = $this->actingAs($user)->getJson("/api/accounts/{$account->id}/balances");

    $response->assertForbidden();
});

it('can delete a balance record', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();
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
    $account = Account::factory()->withBalance()->for($otherUser)->create();
    $balance = AccountBalance::factory()->for($account)->create();

    $response = $this->actingAs($user)->deleteJson("/api/accounts/{$account->id}/balances/{$balance->id}");

    $response->assertForbidden();

    $this->assertDatabaseHas('account_balances', [
        'id' => $balance->id,
    ]);
});

it('returns 404 when deleting balance from wrong account', function () {
    $user = User::factory()->create();
    $account1 = Account::factory()->withBalance()->for($user)->create();
    $account2 = Account::factory()->withBalance()->for($user)->create();
    $balance = AccountBalance::factory()->for($account1)->create();

    $response = $this->actingAs($user)->deleteJson("/api/accounts/{$account2->id}/balances/{$balance->id}");

    $response->assertNotFound();

    $this->assertDatabaseHas('account_balances', [
        'id' => $balance->id,
    ]);
});

it('can update current balance with invested_amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", [
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.balance', 500000)
        ->assertJsonPath('data.invested_amount', 400000);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);
});

it('can update current balance without invested_amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", [
        'balance' => 500000,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.balance', 500000);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 500000,
        'invested_amount' => null,
    ]);
});

it('can store balance with invested_amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/accounts/{$account->id}/balances", [
        'balance' => 300000,
        'balance_date' => now()->subDays(5)->toDateString(),
        'invested_amount' => 250000,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.balance', 300000)
        ->assertJsonPath('data.invested_amount', 250000);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 300000,
        'invested_amount' => 250000,
    ]);
});

it('can store balance without invested_amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/accounts/{$account->id}/balances", [
        'balance' => 300000,
        'balance_date' => now()->subDays(5)->toDateString(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.balance', 300000);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 300000,
        'invested_amount' => null,
    ]);
});

it('can store a zero balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/accounts/{$account->id}/balances", [
        'balance' => 0,
        'balance_date' => now()->subDays(5)->toDateString(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.balance', 0);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 0,
    ]);
});

it('can update the current balance to zero', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    AccountBalance::factory()->for($account)->create([
        'balance_date' => now()->toDateString(),
        'balance' => 100000,
    ]);

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", [
        'balance' => 0,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.balance', 0);

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 0,
    ]);
});

it('preserves existing invested_amount when updating balance without it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    AccountBalance::factory()->for($account)->create([
        'balance_date' => now()->toDateString(),
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);

    $response = $this->actingAs($user)->putJson("/api/accounts/{$account->id}/balance/current", [
        'balance' => 600000,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('data.balance', 600000);

    $this->assertDatabaseCount('account_balances', 1);
    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 600000,
        'invested_amount' => 400000,
    ]);
});

// A balance date is a calendar day, not an instant. Serialised as a full UTC
// datetime it lost the frontend's date-only guard and rendered as the day
// before for anyone at a negative offset — the symptom reported from Argentina.
it('serialises the balance date as a plain calendar day, like a transaction date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withBalance()->for($user)->create();

    AccountBalance::factory()->for($account)->create([
        'balance_date' => '2026-09-09',
        'balance' => 1000,
    ]);

    $response = $this->actingAs($user)->getJson("/api/accounts/{$account->id}/balances");

    expect($response->json('data.0.balance_date'))->toBe('2026-09-09');
});

it('refuses a balance for a credit card on both write endpoints', function (string $method, string $path, array $payload) {
    $user = User::factory()->create();
    $card = Account::factory()->creditCard()->for($user)->create();

    $this->actingAs($user)
        ->json($method, "/api/accounts/{$card->id}/{$path}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('balance');

    expect($card->balances()->count())->toBe(0);
})->with([
    'store' => ['POST', 'balances', ['balance_date' => '2026-01-31', 'balance' => 50000]],
    'update current' => ['PUT', 'balance/current', ['balance' => 50000]],
]);

it('answers 403 rather than 422 for another user\'s credit card', function () {
    $card = Account::factory()->creditCard()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/accounts/{$card->id}/balances", ['balance_date' => '2026-01-31', 'balance' => 50000])
        ->assertForbidden();
});

it('still deletes a balance a credit card was left with', function () {
    $user = User::factory()->create();
    $card = Account::factory()->creditCard()->for($user)->create();
    $balance = AccountBalance::factory()->for($card)->create();

    $this->actingAs($user)
        ->deleteJson("/api/accounts/{$card->id}/balances/{$balance->id}")
        ->assertNoContent();
});
