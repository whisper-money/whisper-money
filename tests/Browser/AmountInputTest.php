<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('formats amount on blur', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    // Pinned: the factory picks a random type, and the add button is
    // off for a user whose only account cannot hold a transaction.
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'type' => 'checking',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('[data-testid="add-transaction-button"]')
        ->fill('#amount', '1234.5')
        ->click('#description')
        ->assertValue('#amount', '1,234.50')
        ->assertNoJavascriptErrors();
});

it('accepts comma as decimal separator', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    // Pinned: the factory picks a random type, and the add button is
    // off for a user whose only account cannot hold a transaction.
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'type' => 'checking',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('[data-testid="add-transaction-button"]')
        ->fill('#amount', '10,50')
        ->click('#description')
        ->assertValue('#amount', '10.50')
        ->assertNoJavascriptErrors();
});

it('can create a transaction with amount input', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Checking',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('[data-testid="add-transaction-button"]')
        ->assertSee('Transaction')
        ->fill('#description', 'Test Transaction')
        ->fill('#amount', '123.45')
        // The account, category and label fields open behind the reveal.
        ->click('[data-testid="toggle-more-options"]')
        ->click('[data-testid="account-select"]')
        ->waitForText('Test Checking', 5)
        ->click('[role="option"]:has-text("Test Checking")')
        ->click('[data-testid="category-select"]')
        ->click($category->name)
        ->click('[data-testid="submit-transaction"]')
        ->assertDontSee('Create Transaction')
        ->assertPathIs('/transactions')
        ->waitForText('Test Transaction', 15)
        ->assertSee('$123.45')
        ->assertNoJavascriptErrors();

    // The type toggle defaults to Expense, so a typed 123.45 stores negative.
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => -12345,
    ]);
});

it('commits the amount when pressing enter', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Checking',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('[data-testid="add-transaction-button"]')
        ->fill('#description', 'Test Transaction Enter')
        // The account, category and label fields open behind the reveal.
        ->click('[data-testid="toggle-more-options"]')
        ->click('[data-testid="account-select"]')
        ->waitForText('Test Checking', 5)
        ->click('[role="option"]:has-text("Test Checking")')
        ->click('[data-testid="category-select"]')
        ->click($category->name)
        // The amount goes in last and Enter submits from inside it, so the
        // field never blurs: the keypress itself has to commit the value.
        ->fill('#amount', '99.99')
        ->keys('#amount', ['Enter'])
        ->assertDontSee('Create Transaction')
        ->assertPathIs('/transactions')
        ->waitForText('Test Transaction Enter', 15)
        ->assertNoJavascriptErrors();

    // The type toggle defaults to Expense, so a typed 99.99 stores negative.
    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => -9999,
    ]);
});

it('accepts negative amounts', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Checking',
        'currency_code' => 'USD',
        'type' => 'checking',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('[data-testid="add-transaction-button"]')
        ->fill('#description', 'Test Negative Amount')
        ->fill('#amount', '-50.00')
        // The account, category and label fields open behind the reveal.
        ->click('[data-testid="toggle-more-options"]')
        ->click('[data-testid="account-select"]')
        ->waitForText('Test Checking', 5)
        ->click('[role="option"]:has-text("Test Checking")')
        ->click('[data-testid="category-select"]')
        ->click($category->name)
        ->click('[data-testid="submit-transaction"]')
        ->assertDontSee('Create Transaction')
        ->assertPathIs('/transactions')
        ->waitForText('Test Negative Amount', 15)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => -5000,
    ]);
});
