<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
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
        ->click('Transaction')
        ->wait(0.5)
        ->assertSee('Create Transaction')
        ->assertNoJavascriptErrors();
});

it('can create a transaction', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Test Bank']);

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
        ->click('Transaction')
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
    $bank = Bank::factory()->create(['name' => 'Filter Bank']);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Daily Account',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Weekly groceries',
        'amount' => -4500,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Electric bill',
        'amount' => -8000,
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->waitForText('Weekly groceries', 10)
        ->assertSee('Electric bill')
        ->fill('input[placeholder="Search description or notes..."]', 'groceries')
        ->wait(1)
        ->assertSee('Weekly groceries')
        ->assertDontSee('Electric bill')
        ->assertNoJavascriptErrors();
});

it('can edit an existing transaction from the list', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Edit Tx Bank']);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);
    $replacementCategory = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Dining Out',
    ]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Editing Account',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Original transaction note',
        'amount' => -3200,
        'notes' => 'Original note',
        'source' => 'manually_created',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->waitForText('Original transaction note', 10)
        ->click('Original transaction note')
        ->wait(1)
        ->assertSee('Edit Transaction')
        ->fill('#description', 'Updated dinner transaction')
        ->click('[data-testid="category-select"]')
        ->wait(1)
        ->click('Dining Out')
        ->fill('#notes', 'Updated note for browser test')
        ->click('[data-testid="submit-transaction"]')
        ->wait(3)
        ->waitForText('Updated dinner transaction', 10)
        ->assertSee('Dining Out')
        ->assertNoJavascriptErrors();

    $updatedTransaction = $transaction->fresh();

    expect($updatedTransaction->description)->toBe('Updated dinner transaction');
    expect($updatedTransaction->notes)->toBe('Updated note for browser test');
    expect($updatedTransaction->category_id)->toBe($replacementCategory->id);
});

it('can delete a transaction from the actions menu', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Delete Tx Bank']);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Household',
    ]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Delete Account',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Disposable transaction',
        'amount' => -1500,
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->waitForText('Disposable transaction', 10)
        ->click('button:has-text("Open menu")')
        ->wait(0.5)
        ->click('Delete')
        ->wait(0.5)
        ->assertSee('Delete Transaction')
        ->click('button:has-text("Delete")')
        ->wait(3)
        ->assertDontSee('Disposable transaction')
        ->assertNoJavascriptErrors();
});
