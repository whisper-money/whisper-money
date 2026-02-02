<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view bank accounts page', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->assertSee('Manage your bank accounts')
        ->assertNoJavascriptErrors();
});

it('shows existing accounts in list', function () {
    $user = User::factory()->onboarded()->create();
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
    $this->setupEncryptionKey($page);

    $page->assertSee('Bank accounts')
        ->waitForText('Test Bank')
        ->assertSee('Checking')
        ->assertSee('USD')
        ->assertNoJavascriptErrors();
});

it('can open create account dialog', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->assertSee('Add a new bank account to track your transactions')
        ->assertNoJavascriptErrors();
});

it('can create a new bank account', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->fill('#display_name', 'My Savings Account')
        ->click('[data-testid="bank-select"]')
        ->wait(0.5)
        ->fill('input[placeholder="Search bank..."]', 'My Bank')
        ->wait(0.5)
        ->click('My Bank')
        ->click('button[name="type"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Savings")')
        ->wait(0.3)
        ->click('button[name="currency_code"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(0.3)
        ->click('[data-testid="submit-account"]')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'type' => 'savings',
        'currency_code' => 'EUR',
    ]);
});

it('shows empty state when no accounts exist', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->waitForText('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can filter accounts by name', function () {
    $user = User::factory()->onboarded()->create();
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
    $this->setupEncryptionKey($page);

    $page->assertSee('Bank accounts')
        ->waitForText('Test Bank')
        ->fill('input[placeholder="Filter accounts..."]', 'Checking')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
});

it('can edit an existing account via dropdown menu', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Edit Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $page->wait(1);
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'Old Account Name', 'Edit Bank', 'Checking', 'USD');

    $page->navigate('/settings/accounts')->wait(5);

    $page->assertSee('Bank accounts')
        ->click('button[aria-label="Open menu"]')
        ->wait(0.5)
        ->click('Edit')
        ->wait(1)
        ->assertSee('Edit Account')
        ->fill('#display_name', 'Updated Account Name')
        ->click('button[type="submit"]:has-text("Update")')
        ->wait(2);

    $page->navigate('/settings/accounts')->wait(5);

    $page->assertSee('Updated Account Name')
        ->assertNoJavascriptErrors();
});

it('can delete an account via dropdown menu', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Delete Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $page->wait(1);
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'Account To Delete', 'Delete Bank', 'Checking', 'USD');

    $page->navigate('/settings/accounts')->wait(5);

    $page->assertSee('Bank accounts')
        ->assertSee('Account To Delete')
        ->click('button[aria-label="Open menu"]')
        ->wait(0.5)
        ->click('Delete')
        ->wait(0.5)
        ->assertSee('Delete Account')
        ->fill('#confirm', 'DELETE')
        ->wait(0.3)
        ->click('button[type="submit"]:has-text("Delete")')
        ->wait(2);

    $page->navigate('/settings/accounts')->wait(5);

    $page->assertDontSee('Account To Delete')
        ->assertNoJavascriptErrors();
});
