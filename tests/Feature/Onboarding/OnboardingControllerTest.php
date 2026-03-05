<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

it('returns categories and transactions props on onboarding index', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $bank = Bank::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    // One uncategorized and one categorized transaction
    $uncategorized = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->has('categories', 1)
            ->has('transactions', 1)
            ->where('transactions.0.id', $uncategorized->id)
        );
});

it('returns only uncategorized transactions in the transactions prop', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $bank = Bank::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->plaintext()->count(3)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    Transaction::factory()->plaintext()->count(2)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 3)
        );
});

it('does not return transactions belonging to other users', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $other = User::factory()->create(['onboarded_at' => null]);
    $bank = Bank::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id, 'bank_id' => $bank->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $other->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 0)
        );
});

it('returns banks and accounts props on onboarding index', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $globalBank = Bank::factory()->create(['user_id' => null]);
    $userBank = Bank::factory()->create(['user_id' => $user->id]);
    $otherBank = Bank::factory()->create(['user_id' => User::factory()->create()->id]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->has('banks', 2) // global + user's own bank
            ->has('accounts')
        );
});
