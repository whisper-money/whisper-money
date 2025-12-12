<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('guests cannot access transactions page', function () {
    $response = $this->get(route('transactions.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can access transactions page', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('categories')
        ->has('accounts')
        ->has('banks')
    );
});

test('authenticated users can access categorize transactions page', function () {
    $user = User::factory()->onboarded()->create();

    $response = actingAs($user)->get(route('transactions.categorize'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/categorize')
        ->has('categories')
        ->has('accounts')
        ->has('banks')
    );
});

test('guests cannot access categorize transactions page', function () {
    $response = $this->get(route('transactions.categorize'));

    $response->assertRedirect(route('login'));
});

test('users can update their own transaction category', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'category_id' => $category->id,
    ]);
});

test('users can update transaction notes', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'notes' => 'encrypted_notes_content',
        'notes_iv' => str_repeat('c', 16),
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'notes' => 'encrypted_notes_content',
        'notes_iv' => str_repeat('c', 16),
    ]);
});

test('users can clear transaction category', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => null,
    ]);

    $response->assertSuccessful();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'category_id' => null,
    ]);
});

test('users cannot update other users transactions', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create(['encryption_salt' => str_repeat('b', 24)]);
    $account = Account::factory()->create(['user_id' => $otherUser->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $category->id,
    ]);

    $response->assertForbidden();
});

test('category_id must exist when updating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => 99999,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['category_id']);
});

test('notes_iv must be exactly 16 characters', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'notes' => 'encrypted_notes',
        'notes_iv' => 'invalid',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['notes_iv']);
});

test('users can soft delete their own transactions', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->deleteJson(route('transactions.destroy', $transaction));

    $response->assertSuccessful();
    $this->assertSoftDeleted('transactions', [
        'id' => $transaction->id,
    ]);
});

test('users cannot delete other users transactions', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create(['encryption_salt' => str_repeat('b', 24)]);
    $account = Account::factory()->create(['user_id' => $otherUser->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $account->id,
    ]);

    $response = actingAs($user)->deleteJson(route('transactions.destroy', $transaction));

    $response->assertForbidden();
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'deleted_at' => null,
    ]);
});

test('transactions index page passes user categories', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create();

    $userCategory = Category::factory()->create(['user_id' => $user->id, 'name' => 'My Category']);
    Category::factory()->create(['user_id' => $otherUser->id, 'name' => 'Other Category']);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('categories', 1)
        ->where('categories.0.name', 'My Category')
    );
});

test('transactions index page passes user accounts', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->create();

    Account::factory()->create(['user_id' => $user->id, 'name' => 'encrypted_name_1', 'name_iv' => str_repeat('a', 16)]);
    Account::factory()->create(['user_id' => $otherUser->id, 'name' => 'encrypted_name_2', 'name_iv' => str_repeat('b', 16)]);

    $response = actingAs($user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('accounts', 1)
    );
});

test('users can create a new transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 15050,
        'currency_code' => 'USD',
        'notes' => 'encrypted_notes',
        'notes_iv' => str_repeat('n', 16),
        'source' => 'manually_created',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();
    $response->assertJsonStructure([
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
            'notes',
            'notes_iv',
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
        'amount' => 15050,
        'currency_code' => 'USD',
        'source' => 'manually_created',
    ]);
});

test('users can create a transaction without category', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 7525,
        'currency_code' => 'EUR',
        'source' => 'manually_created',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'encrypted_description',
        'amount' => 7525,
    ]);
});

test('users can create a transaction without notes', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
        'currency_code' => 'USD',
        'source' => 'imported',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertCreated();
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'notes' => null,
        'notes_iv' => null,
    ]);
});

test('account_id is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();

    $transactionData = [
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['account_id']);
});

test('description is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['description']);
});

test('amount is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['amount']);
});

test('transaction_date is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'amount' => 10000,
        'currency_code' => 'USD',
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['transaction_date']);
});

test('currency_code is required when creating transaction', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $transactionData = [
        'account_id' => $account->id,
        'description' => 'encrypted_description',
        'description_iv' => str_repeat('d', 16),
        'transaction_date' => '2025-11-11',
        'amount' => 10000,
    ];

    $response = actingAs($user)->postJson(route('transactions.store'), $transactionData);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['currency_code']);
});
