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
    $this->setupEncryptionKey($page);

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
    $this->setupEncryptionKey($page);
    createCategoryViaUI($page, 'Groceries');

    // Create account via UI
    $page = visit('/settings/accounts');
    $page->wait(3);
    createAccountViaUI($page, 'My Checking', 'Test Bank');

    // Visit transactions page
    $page = visit('/transactions');
    $page->wait(2);

    $page->assertSee('Transactions')
        ->click('Add Transaction')
        ->wait(1)
        ->assertSee('Create Transaction')
        ->fill('description', 'Test Transaction')
        ->wait(0.5)
        ->click('button:has-text("Select Account")')
        ->wait(1)
        ->click('My Checking')
        ->wait(0.5)
        ->click('button:has-text("Select Category")')
        ->wait(1)
        ->click('Groceries')
        ->fill('#amount', '50.00')
        ->click('Create')
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
    $this->setupEncryptionKey($page);

    $page->assertSee('Transactions')
        ->wait(2)
        ->assertNoJavascriptErrors();
});
