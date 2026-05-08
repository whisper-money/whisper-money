<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

it('clicking an expense category on cashflow page navigates to transactions with filters', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
        'name' => 'Groceries',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -5000,
        'transaction_date' => now()->startOfMonth(),
    ]);

    $period = now()->format('Y-m');

    $page = $this->actingAs($user)->visit("/cashflow?period={$period}");

    $page->waitForText('Groceries', 10)
        ->click('Groceries')
        ->wait(2)
        ->assertPathIs('/transactions')
        ->assertQueryStringHas('category_ids', $category->id)
        ->assertNoJavascriptErrors();
});

it('clicking an income category on cashflow page navigates to transactions with filters', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => 200000,
        'transaction_date' => now()->startOfMonth(),
    ]);

    $period = now()->format('Y-m');

    $page = $this->actingAs($user)->visit("/cashflow?period={$period}");

    $page->waitForText('Salary', 10)
        ->click('Salary')
        ->wait(2)
        ->assertPathIs('/transactions')
        ->assertQueryStringHas('category_ids', $category->id)
        ->assertNoJavascriptErrors();
});
