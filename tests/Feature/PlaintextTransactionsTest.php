<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('creating plaintext transaction succeeds', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'Grocery shopping',
        'transaction_date' => '2025-11-11',
        'amount' => 5000,
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'description' => 'Grocery shopping',
        'description_iv' => null,
    ]);
});

test('creating plaintext transaction with notes succeeds', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'Coffee',
        'transaction_date' => '2025-11-11',
        'amount' => 350,
        'currency_code' => 'USD',
        'notes' => 'Morning coffee at the cafe',
        'source' => 'manually_created',
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'description' => 'Coffee',
        'description_iv' => null,
        'notes' => 'Morning coffee at the cafe',
        'notes_iv' => null,
    ]);
});

test('encrypted transactions still work', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $response = actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'encrypted_content',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 1000,
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'description' => 'encrypted_content',
        'description_iv' => str_repeat('d', 16),
    ]);
});

test('encrypted and plaintext transactions can coexist', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    // Create an encrypted transaction (legacy)
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'encrypted_content',
        'description_iv' => str_repeat('e', 16),
    ]);

    // Create a plaintext transaction
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Plaintext transaction',
    ]);

    expect(Transaction::where('user_id', $user->id)->count())->toBe(2);
    expect(Transaction::where('user_id', $user->id)->whereNull('description_iv')->count())->toBe(1);
    expect(Transaction::where('user_id', $user->id)->whereNotNull('description_iv')->count())->toBe(1);
});

test('updating transaction without description_iv works', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'description' => 'Updated plaintext',
        'description_iv' => null,
        'notes' => 'Updated notes',
        'notes_iv' => null,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'description' => 'Updated plaintext',
        'description_iv' => null,
        'notes' => 'Updated notes',
        'notes_iv' => null,
    ]);
});
