<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view bank accounts page', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->assertSee('Manage your bank accounts')
        ->assertNoJavascriptErrors();
});

it('shows existing accounts in list', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->waitForText('Test Bank')
        ->assertSee('Checking')
        ->assertSee('USD')
        ->assertNoJavascriptErrors();
});

it('can open create account dialog', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->assertSee('Add a new bank account to track your transactions')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('can create a new bank account', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->fill('#display_name', 'My Savings Account')
        ->click('Select a bank...')
        ->wait(0.5)
        ->fill('input[placeholder="Search banks..."]', 'My Bank')
        ->wait(0.5)
        ->click('My Bank')
        ->click('Select account type')
        ->wait(0.3)
        ->click('Savings')
        ->click('Select currency')
        ->wait(0.3)
        ->click('EUR')
        ->click('button[type="submit"]')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'savings',
        'currency_code' => 'EUR',
    ]);
})->skip('Requires browser encryption key setup');

it('shows empty state when no accounts exist', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->waitForText('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can filter accounts by name', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Checking Account',
        'name_iv' => str_repeat('b', 16),
    ]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Savings Account',
        'name_iv' => str_repeat('c', 16),
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->waitForText('Test Bank')
        ->fill('input[placeholder="Filter accounts..."]', 'Checking')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
});

it('can edit an existing account via dropdown menu', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Old Account Name',
        'name_iv' => str_repeat('b', 16),
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->click('button[aria-label="Open menu"]')
        ->wait(0.3)
        ->click('Edit')
        ->wait(0.5)
        ->assertSee('Edit Account')
        ->fill('#display_name', 'Updated Account Name')
        ->click('Save')
        ->wait(2)
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('can delete an account via dropdown menu', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Account To Delete',
        'name_iv' => str_repeat('b', 16),
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->click('button[aria-label="Open menu"]')
        ->wait(0.3)
        ->click('Delete')
        ->wait(0.5)
        ->assertSee('Delete Account')
        ->click('Delete')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseMissing('accounts', [
        'id' => $account->id,
    ]);
})->skip('Requires browser encryption key setup');
