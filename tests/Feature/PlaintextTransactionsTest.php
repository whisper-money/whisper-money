<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

test('plaintext-transactions feature flag defaults to false', function () {
    $user = User::factory()->onboarded()->create();

    expect(Feature::for($user)->active('plaintext-transactions'))->toBeFalse();
});

test('creating transaction without description_iv fails when flag is inactive', function () {
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

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['description_iv']);
});

test('creating plaintext transaction succeeds when flag is active', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('plaintext-transactions');
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

test('creating plaintext transaction with notes succeeds when flag is active', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('plaintext-transactions');
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

test('encrypted transactions still work when flag is active', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('plaintext-transactions');
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

    // Create an encrypted transaction (before flag was activated)
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'encrypted_content',
        'description_iv' => str_repeat('e', 16),
    ]);

    // Activate the flag and create a plaintext transaction
    Feature::for($user)->activate('plaintext-transactions');

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Plaintext transaction',
    ]);

    expect(Transaction::where('user_id', $user->id)->count())->toBe(2);
    expect(Transaction::where('user_id', $user->id)->whereNull('description_iv')->count())->toBe(1);
    expect(Transaction::where('user_id', $user->id)->whereNotNull('description_iv')->count())->toBe(1);
});

test('updating transaction without description_iv works when flag is active', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('plaintext-transactions');
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

test('plaintext-transactions feature flag is shared with frontend', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate('plaintext-transactions');

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('features.plaintext-transactions', true)
    );
});

test('plaintext-transactions feature flag defaults to false in frontend', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('features.plaintext-transactions', false)
    );
});
