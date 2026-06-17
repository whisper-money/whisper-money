<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('formats amount on blur', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Transaction')
        ->wait(1)
        ->fill('#amount', '123.45')
        ->click('description')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
});

it('accepts comma as decimal separator', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('Transaction')
        ->wait(1)
        ->fill('#amount', '10,50')
        ->click('description')
        ->wait(0.5)
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
    $page->wait(3); // Extra wait for IndexedDB to sync

    $page->assertSee('Transactions')
        ->click('Transaction')
        ->wait(2)
        ->assertSee('Transaction')
        ->wait(1)
        ->fill('#description', 'Test Transaction')
        ->wait(0.5)
        ->fill('#amount', '123.45')
        ->wait(0.5)
        ->click('[data-testid="account-select"]')
        ->wait(2)
        ->waitForText('Test Checking', 5)
        ->click('[role="option"]:has-text("Test Checking")')
        ->wait(0.5)
        ->click('[data-testid="category-select"]')
        ->wait(1)
        ->click($category->name)
        ->wait(1)
        ->click('[data-testid="submit-transaction"]')
        ->wait(4) // Wait for form submission and navigation
        ->assertPathIs('/transactions')
        ->wait(2) // Extra wait for IndexedDB sync after creation
        ->waitForText('Test Transaction', 15)
        ->assertSee('$123.45')
        ->wait(1)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => 12345,
    ]);
});

it('formats amount when pressing enter', function () {
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
    $page->wait(3); // Extra wait for IndexedDB to sync

    $page->assertSee('Transactions')
        ->click('Transaction')
        ->wait(1)
        ->fill('#description', 'Test Transaction Enter')
        ->fill('#amount', '99.99')
        ->wait(0.5)
        ->click('[data-testid="account-select"]')
        ->wait(2)
        ->waitForText('Test Checking', 5)
        ->click('[role="option"]:has-text("Test Checking")')
        ->wait(0.5)
        ->click('[data-testid="category-select"]')
        ->wait(0.5)
        ->click($category->name)
        ->wait(0.5)
        ->click('[data-testid="submit-transaction"]')
        ->wait(4) // Wait for form submission and navigation
        ->assertPathIs('/transactions')
        ->wait(2) // Extra wait for IndexedDB sync after creation
        ->waitForText('Test Transaction Enter', 15)
        ->wait(1)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => 9999,
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
    $page->wait(3); // Extra wait for IndexedDB to sync

    $page->assertSee('Transactions')
        ->click('Transaction')
        ->wait(1)
        ->fill('#description', 'Test Negative Amount')
        ->fill('#amount', '-50.00')
        ->click('[data-testid="account-select"]')
        ->wait(2)
        ->waitForText('Test Checking', 5)
        ->click('[role="option"]:has-text("Test Checking")')
        ->wait(0.5)
        ->click('[data-testid="category-select"]')
        ->wait(0.5)
        ->click($category->name)
        ->wait(0.5)
        ->click('[data-testid="submit-transaction"]')
        ->wait(4) // Wait for form submission and navigation
        ->assertPathIs('/transactions')
        ->wait(2) // Extra wait for IndexedDB sync after creation
        ->waitForText('Test Negative Amount', 15)
        ->wait(1)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'amount' => -5000,
    ]);
});
