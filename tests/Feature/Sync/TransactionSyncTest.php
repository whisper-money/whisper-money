<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

it('can fetch user transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transactions = Transaction::factory()->count(3)->for($user)->for($account)->create();

    $response = $this->actingAs($user)->getJson('/api/sync/transactions');

    $response->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'user_id',
                    'account_id',
                    'category_id',
                    'description',
                    'description_iv',
                    'transaction_date',
                    'amount',
                    'currency_code',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
});

it('only returns user own transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->for($user)->for($account)->create();
    Transaction::factory()->for($otherUser)->for($otherAccount)->create();

    $response = $this->actingAs($user)->getJson('/api/sync/transactions');

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

it('can filter transactions by updated_at', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $oldTransaction = Transaction::factory()->for($user)->for($account)->create([
        'updated_at' => now()->subDays(2),
    ]);

    $newTransaction = Transaction::factory()->for($user)->for($account)->create([
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson('/api/sync/transactions?since='.now()->subDay()->toISOString());

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newTransaction->id);
});

it('can create a transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();

    $transactionData = [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'encrypted_description',
        'description_iv' => '1234567890123456',
        'transaction_date' => now()->toDateString(),
        'amount' => 10050,
        'currency_code' => 'USD',
        'notes' => null,
        'notes_iv' => null,
        'source' => 'manually_created',
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/transactions', $transactionData);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'account_id',
                'category_id',
                'description',
                'description_iv',
                'transaction_date',
                'amount',
                'currency_code',
                'source',
                'created_at',
                'updated_at',
            ],
        ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'encrypted_description',
        'amount' => 10050,
        'source' => 'manually_created',
    ]);
});

it('can create a transaction with a UUID', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $uuid = (string) Str::uuid7();

    $transactionData = [
        'id' => $uuid,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'encrypted_description',
        'description_iv' => '1234567890123456',
        'transaction_date' => now()->toDateString(),
        'amount' => 10050,
        'currency_code' => 'USD',
        'notes' => null,
        'notes_iv' => null,
        'source' => 'imported',
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/transactions', $transactionData);

    $response->assertCreated()
        ->assertJsonPath('data.id', $uuid);

    $this->assertDatabaseHas('transactions', [
        'id' => $uuid,
        'user_id' => $user->id,
        'source' => 'imported',
    ]);
});

it('validates required fields when creating a transaction', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/sync/transactions', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['account_id', 'description', 'description_iv', 'transaction_date', 'amount', 'currency_code', 'source']);
});

it('returns existing transaction when creating with duplicate UUID (idempotent)', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $uuid = (string) Str::uuid7();

    // Create the transaction first
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'id' => $uuid,
        'description' => 'original_description',
        'amount' => 10050,
    ]);

    // Try to create again with the same UUID
    $transactionData = [
        'id' => $uuid,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'new_description',
        'description_iv' => '1234567890123456',
        'transaction_date' => now()->toDateString(),
        'amount' => 99999,
        'currency_code' => 'USD',
        'notes' => null,
        'notes_iv' => null,
        'source' => 'imported',
    ];

    $response = $this->actingAs($user)->postJson('/api/sync/transactions', $transactionData);

    // Should return 200 with existing transaction, not 201 or error
    $response->assertOk()
        ->assertJsonPath('data.id', $uuid)
        ->assertJsonPath('data.amount', 10050)
        ->assertJsonPath('data.description', 'original_description');

    // Ensure no duplicate was created
    expect(Transaction::where('id', $uuid)->count())->toBe(1);
});

it('can update a transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'amount' => 10000,
        'description' => 'old_description',
    ]);

    $updateData = [
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'new_description',
        'description_iv' => $transaction->description_iv,
        'transaction_date' => $transaction->transaction_date->toDateString(),
        'amount' => 20000,
        'currency_code' => $transaction->currency_code,
        'notes' => null,
        'notes_iv' => null,
        'source' => 'manually_created',
    ];

    $response = $this->actingAs($user)->patchJson("/api/sync/transactions/{$transaction->id}", $updateData);

    $response->assertSuccessful()
        ->assertJsonPath('data.amount', 20000)
        ->assertJsonPath('data.description', 'new_description');

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'amount' => 20000,
        'description' => 'new_description',
    ]);
});

it('cannot update another user transaction', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();
    $transaction = Transaction::factory()->for($otherUser)->for($account)->create();

    $updateData = [
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'hacked',
        'description_iv' => $transaction->description_iv,
        'transaction_date' => $transaction->transaction_date->toDateString(),
        'amount' => 99999,
        'currency_code' => $transaction->currency_code,
        'notes' => null,
        'notes_iv' => null,
        'source' => 'manually_created',
    ];

    $response = $this->actingAs($user)->patchJson("/api/sync/transactions/{$transaction->id}", $updateData);

    $response->assertForbidden();
});

it('can delete a transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create();

    $response = $this->actingAs($user)->deleteJson("/api/sync/transactions/{$transaction->id}");

    $response->assertSuccessful();

    $this->assertSoftDeleted('transactions', [
        'id' => $transaction->id,
    ]);
});

it('cannot delete another user transaction', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($otherUser)->create();
    $transaction = Transaction::factory()->for($otherUser)->for($account)->create();

    $response = $this->actingAs($user)->deleteJson("/api/sync/transactions/{$transaction->id}");

    $response->assertForbidden();

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
    ]);
});
