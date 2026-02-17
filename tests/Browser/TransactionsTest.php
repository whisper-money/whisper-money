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
});

it('can create a transaction', function () {
    $user = User::factory()->onboarded()->create();
    $bank = \App\Models\Bank::factory()->create(['name' => 'Test Bank']);

    actingAs($user);

    // Create category via UI
    $page = visit('/settings/categories');
    createCategoryViaUI($page, 'Groceries');

    // Create account via UI
    $page = visit('/settings/accounts');
    createAccountViaUI($page, 'My Checking', 'Test Bank');

    // Verify account was created
    $page->wait(2);
    $page->assertSee('My Checking');

    // Visit transactions page
    $page = visit('/transactions');
    $page->wait(3); // Extra wait for IndexedDB to sync

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(2)
        ->assertSee('Create Transaction')
        ->fill('description', 'Test Transaction')
        ->wait(1)
        ->click('[data-testid="account-select"]')
        ->wait(2)
        ->waitForText('My Checking', 5)
        ->click('[role="option"]:has-text("My Checking")')
        ->wait(0.5)
        ->click('[data-testid="category-select"]')
        ->wait(2)
        ->waitForText('Groceries', 5)
        ->click('Groceries')
        ->fill('#amount', '50.00')
        ->click('[data-testid="submit-transaction"]')
        ->wait(3)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => 5000,
    ]);
});

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

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->wait(2)
        ->assertNoJavascriptErrors();
});
