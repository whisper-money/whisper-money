<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can open import transactions drawer', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Test Account',
        'name_iv' => str_repeat('b', 16),
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->assertSee('Import Transactions')
        ->assertSee('Select the account to import transactions into')
        ->assertNoJavascriptErrors();
});

it('shows no accounts message when none exist', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
});

it('can select account for import', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->assertSee('My Bank')
        ->assertSee('USD')
        ->click('label')
        ->wait(0.3)
        ->click('Next')
        ->waitFor('Drop your file here')
        ->assertSee('Supports CSV, XLS, and XLSX files')
        ->assertNoJavascriptErrors();
});

it('can upload a CSV file for import', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->click('label')
        ->wait(0.3)
        ->click('Next')
        ->waitFor('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->assertSee('test-transactions.csv')
        ->assertNoJavascriptErrors();
});

it('can complete full import flow', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->waitFor('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->assertSee('test-transactions.csv')
        ->click('Next')
        ->wait(1)
        ->waitFor('Transaction Date')
        ->click('#date-column')
        ->wait(0.3)
        ->click('Date')
        ->click('#description-column')
        ->wait(0.3)
        ->click('Description')
        ->click('#amount-column')
        ->wait(0.3)
        ->click('Amount')
        ->wait(0.5)
        ->click('Preview Transactions')
        ->wait(2)
        ->assertSee('Total')
        ->assertSee('New')
        ->assertNoJavascriptErrors();
});

it('shows column mapping step after file upload', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->waitFor('Drop your file here')
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
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);
    $bank = Bank::factory()->create(['name' => 'My Bank']);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->waitFor('drawer')
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->waitFor('Drop your file here')
        ->click('Back')
        ->wait(0.5)
        ->assertSee('Select the account to import transactions into')
        ->assertNoJavascriptErrors();
});
