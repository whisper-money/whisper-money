<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view transactions page', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->assertSee('View and manage your transactions')
        ->assertNoJavascriptErrors();
});

it('can open add transaction dialog', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(0.5)
        ->assertSee('Create Transaction')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('can create a transaction', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(0.5)
        ->fill('description', 'Test Transaction')
        ->click('Select Account')
        ->wait(0.5)
        ->click($account->name)
        ->click('Select Category')
        ->wait(0.5)
        ->click($category->name)
        ->fill('#amount', '50.00')
        ->click('Create')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => 5000,
    ]);
})->skip('Requires browser encryption key setup');

it('shows empty state when no transactions exist', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->waitForText('No transactions found')
        ->assertNoJavascriptErrors();
});

it('can filter transactions by search text', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->fill('input[placeholder="Search transactions..."]', 'grocery')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');
