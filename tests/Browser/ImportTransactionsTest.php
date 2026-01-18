<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can open import transactions drawer', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'Test Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'Test Account', 'Test Bank');

    // Visit transactions page (don't use navigate as it doesn't trigger full page load)
    $page = visit('/transactions');
    $page->wait(2);

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->assertSee('Import Transactions')
        ->assertSee('Select the account to import transactions into')
        ->waitForText('Test Account', 5)
        ->assertNoJavascriptErrors();
});

it('shows no accounts message when none exist', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');
    $this->setupEncryptionKey($page);

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can select account for import', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'My Checking', 'My Bank', 'Checking', 'USD');

    // Visit transactions page (don't use navigate as it doesn't trigger full page load)
    $page = visit('/transactions');
    $page->wait(2);

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->waitForText('My Bank', 5)
        ->assertSee('USD')
        ->click('input[type="radio"]')
        ->wait(0.5)
        ->click('Next')
        ->wait(1)
        ->assertSee('Drop your file here')
        ->assertSee('Supports CSV, XLS, and XLSX files')
        ->assertNoJavascriptErrors();
});

it('can upload a CSV file for import', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'My Checking', 'My Bank', 'Checking', 'USD');

    $page->navigate('/transactions')->wait(2);

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->waitForText('My Bank', 5)
        ->click('input[type="radio"]')
        ->wait(0.5)
        ->click('Next')
        ->wait(1)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->assertSee('test-transactions.csv')
        ->assertNoJavascriptErrors();
});

it('can complete full import flow', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'My Checking', 'My Bank', 'Checking', 'USD');

    $page->navigate('/transactions')->wait(2);

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->waitForText('My Bank', 5)
        ->click('input[type="radio"]')
        ->wait(0.5)
        ->click('Next')
        ->wait(1)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->assertSee('test-transactions.csv')
        ->click('Next')
        ->wait(1)
        ->assertSee('Transaction Date')
        ->click('#date-column')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Date")')
        ->wait(0.3)
        ->click('#description-column')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Description")')
        ->wait(0.3)
        ->click('#amount-column')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Amount")')
        ->wait(0.5)
        ->click('Preview Transactions')
        ->wait(2)
        ->assertSee('Total')
        ->assertSee('New')
        ->assertNoJavascriptErrors();
});

it('shows column mapping step after file upload', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'My Checking', 'My Bank', 'Checking', 'USD');

    $page->navigate('/transactions')->wait(2);

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->waitForText('My Bank', 5)
        ->click('input[type="radio"]')
        ->wait(0.5)
        ->click('Next')
        ->wait(1)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->click('Next')
        ->wait(1)
        ->assertSee('Transaction Date')
        ->assertSee('Description')
        ->assertSee('Amount')
        ->assertSee('Balance (Optional)')
        ->assertNoJavascriptErrors();
});

it('can navigate back through import steps', function () {
    $user = User::factory()->onboarded()->create();
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    $page = visit('/settings/accounts');
    $this->setupEncryptionKey($page);

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'My Checking', 'My Bank', 'Checking', 'USD');

    // Visit transactions page (don't use navigate as it doesn't trigger full page load)
    $page = visit('/transactions');
    $page->wait(2);

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->waitForText('My Bank', 5)
        ->click('input[type="radio"]')
        ->wait(0.5)
        ->click('Next')
        ->wait(1)
        ->assertSee('Drop your file here')
        ->click('Back')
        ->wait(0.5)
        ->assertSee('Select the account to import transactions into')
        ->assertNoJavascriptErrors();
});

it('applies automation rules when importing transactions', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'My Bank']);

    actingAs($user);

    // Create category via UI
    $page = visit('/settings/categories');
    $this->setupEncryptionKey($page);
    createCategoryViaUI($page, 'Groceries');

    // Create account via UI
    $page = visit('/settings/accounts');
    $page->wait(1);
    createAccountViaUI($page, 'My Checking', 'My Bank', 'Checking', 'USD');

    // Create automation rule via UI
    $page = visit('/settings/automation-rules');
    $page->wait(1);
    $page->click('button:has-text("Create Rule")')
        ->wait(0.5)
        ->fill('title', 'Auto Categorize Groceries')
        ->fill('priority', '1')
        ->fill('input[placeholder="Value"]', 'walmart')
        ->click('[data-testid="action-category-select"]')
        ->wait(0.5)
        ->click('Groceries')
        ->click('[data-testid="submit-automation-rule"]')
        ->wait(2);

    $page->navigate('/transactions')->wait(2);

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->click('Import Transactions')
        ->wait(2)
        ->waitForText('My Checking', 5)
        ->click('input[type="radio"]')
        ->wait(0.5)
        ->click('Next')
        ->wait(1)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->click('Next')
        ->wait(1)
        ->click('#date-column')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Date")')
        ->wait(0.3)
        ->click('#description-column')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Description")')
        ->wait(0.3)
        ->click('#amount-column')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Amount")')
        ->wait(0.5)
        ->click('Preview Transactions')
        ->wait(2)
        ->click('Import')
        ->wait(3)
        ->assertSee('imported successfully');

    $page->wait(2);

    expect(\App\Models\Transaction::where('user_id', $user->id)->count())->toBeGreaterThan(0);

    $walmartTransaction = \App\Models\Transaction::where('user_id', $user->id)
        ->whereRaw('LOWER(description) LIKE ?', ['%walmart%'])
        ->first();

    expect($walmartTransaction)->not->toBeNull();

    $groceriesCategory = \App\Models\Category::where('user_id', $user->id)
        ->where('name', 'Groceries')
        ->first();

    expect($walmartTransaction->category_id)->toBe($groceriesCategory->id);
});
