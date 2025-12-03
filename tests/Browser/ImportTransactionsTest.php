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
        ->wait(0.5)
        ->assertSee('Import Transactions')
        ->assertSee('Select the account to import transactions into')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('shows no accounts message when none exist', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);
    Category::factory()->create(['user_id' => $user->id]);

    actingAs($user);

    $page = visit('/transactions');

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(0.3)
        ->click('Import Transactions')
        ->wait(0.5)
        ->assertSee('No accounts found')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

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
        ->wait(0.5)
        ->assertSee('My Bank')
        ->assertSee('USD')
        ->click('label')
        ->wait(0.3)
        ->click('Next')
        ->wait(0.5)
        ->assertSee('Drop your file here')
        ->assertSee('Supports CSV, XLS, and XLSX files')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

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
        ->wait(0.5)
        ->click('label')
        ->wait(0.3)
        ->click('Next')
        ->wait(0.5)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->assertSee('test-transactions.csv')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

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
        ->wait(0.5)
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->wait(0.5)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->assertSee('test-transactions.csv')
        ->click('Next')
        ->wait(1)
        ->assertSee('Transaction Date')
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
})->skip('Requires browser encryption key setup');

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
        ->wait(0.5)
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->wait(0.5)
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
})->skip('Requires browser encryption key setup');

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
        ->wait(0.5)
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->wait(0.5)
        ->assertSee('Drop your file here')
        ->click('Back')
        ->wait(0.5)
        ->assertSee('Select the account to import transactions into')
        ->assertNoJavascriptErrors();
})->skip('Requires browser encryption key setup');

it('applies automation rules when importing transactions', function () {
    $user = User::factory()->create(['encryption_salt' => str_repeat('a', 24)]);

    $groceriesCategory = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
    ]);

    $bank = Bank::factory()->create(['name' => 'My Bank']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'name_iv' => str_repeat('b', 16),
        'currency_code' => 'USD',
    ]);

    \App\Models\AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Auto Categorize Groceries',
        'priority' => 1,
        'rules_json' => [
            'in' => [
                'walmart',
                ['var' => 'description'],
            ],
        ],
        'action_category_id' => $groceriesCategory->id,
        'action_note' => null,
        'action_note_iv' => null,
    ]);

    actingAs($user);

    $page = visit('/transactions');

    $testFile = __DIR__.'/assets/test-transactions.csv';

    $page->assertSee('Transactions')
        ->click('button[aria-label="More actions"]')
        ->wait(1)
        ->press('ArrowDown')
        ->wait(0.2)
        ->press('Enter')
        ->wait(1)
        ->click('label')
        ->wait(0.5)
        ->click('Next')
        ->wait(0.5)
        ->assertSee('Drop your file here')
        ->attach('input[type="file"]', $testFile)
        ->wait(1)
        ->click('Next')
        ->wait(1)
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
        ->click('Import')
        ->wait(3)
        ->assertSee('imported successfully');

    $page->wait(2);

    expect(\App\Models\Transaction::where('user_id', $user->id)->count())->toBeGreaterThan(0);

    $walmartTransaction = \App\Models\Transaction::where('user_id', $user->id)
        ->whereRaw('LOWER(description) LIKE ?', ['%walmart%'])
        ->first();

    expect($walmartTransaction)->not->toBeNull();
    expect($walmartTransaction->category_id)->toBe($groceriesCategory->id);
})->skip('Requires browser encryption key setup');
