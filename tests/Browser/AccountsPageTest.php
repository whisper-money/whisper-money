<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows empty state when no accounts exist', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Accounts')
        ->waitForText('No accounts found')
        ->assertNoJavascriptErrors();
});

it('shows account cards for existing accounts', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Test Bank', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded']);

    $page->assertSee('Accounts')
        ->waitForText('My Checking')
        ->assertSee('Test Bank')
        ->assertNoJavascriptErrors();
});

it('can navigate to account details page', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Nav Bank', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Navigable Account',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded']);

    $page->waitForText('Navigable Account')
        ->click('Details →')
        ->assertSee('Update balance')
        ->assertSee('Nav Bank')
        ->assertNoJavascriptErrors();
});

it('can update and view balance history from account details page', function () {
    $user = User::factory()->onboarded()->create();
    $user->update(['currency_code' => 'EUR']);
    $bank = Bank::factory()->create(['name' => 'Balance Bank', 'logo' => null]);

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Tracked Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2024-01-01',
        'balance' => 10000000,
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded']);

    $page->waitForText('Tracked Checking')
        ->click('Details →')
        ->assertSee('Import balances')
        ->click('Update balance')
        ->assertValue('#balance-amount', '100000.00')
        ->fill('#balance-amount', '120000.00')
        ->click('#balance-date')
        ->fill('#balance-date', '2024-02-01')
        ->click('button[type="submit"]:has-text("Save")')
        ->click('button[aria-label="More options"]')
        ->click('See balances')
        ->assertSee('Balance History')
        ->assertSee('Feb 1, 2024')
        ->assertSee('Jan 1, 2024')
        ->assertNoJavascriptErrors();

    $this->assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => '2024-02-01',
        'balance' => 12000000,
    ]);
});

it('can link and unlink a loan from the real estate details page', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Property Bank', 'logo' => null]);
    $loanBank = Bank::factory()->create(['name' => 'Mortgage Bank', 'logo' => null]);

    $property = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => null,
        'name' => 'Beach Condo',
        'type' => AccountType::RealEstate,
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $property->id,
        'balance_date' => '2024-01-01',
        'balance' => 30000000,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $property->id,
        'property_type' => 'residential',
        'address' => 'Ocean Avenue 1',
        'linked_loan_account_id' => null,
    ]);

    $loan = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $loanBank->id,
        'name' => 'Beach Mortgage',
        'type' => AccountType::Loan,
        'currency_code' => 'EUR',
    ]);

    LoanDetail::factory()->create([
        'account_id' => $loan->id,
        'annual_interest_rate' => 3.1,
        'loan_term_months' => 300,
        'original_amount' => 22000000,
        'start_date' => '2024-01-01',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => '2024-01-01',
        'balance' => 20000000,
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded']);

    $page->waitForText('Beach Condo')
        ->click('Details →')
        ->assertSee('Property Details')
        ->assertDontSee('Linked Mortgage / Loan')
        ->click('Edit')
        ->assertSee('Edit Property Details')
        ->click('xpath=//label[contains(., "Linked Mortgage / Loan")]/following::button[@role="combobox"][1]')
        ->click('[role="option"]:has-text("Beach Mortgage")')
        ->click('button[type="submit"]:has-text("Save")')
        ->assertDontSee('Edit Property Details')
        ->assertSee('Linked Mortgage / Loan')
        ->assertSee('Beach Mortgage (Mortgage Bank)')
        ->click('Edit')
        ->click('xpath=//label[contains(., "Linked Mortgage / Loan")]/following::button[@role="combobox"][1]')
        ->click('[role="option"]:has-text("No linked loan")')
        ->click('button[type="submit"]:has-text("Save")')
        ->assertDontSee('Edit Property Details')
        ->assertDontSee('Beach Mortgage (Mortgage Bank)')
        ->assertNoJavascriptErrors();

    $detail = RealEstateDetail::query()->where('account_id', $property->id)->first();

    expect($detail)->not->toBeNull();
    expect($detail->fresh()->linked_loan_account_id)->toBeNull();
});

it('does not show other users accounts', function () {
    $user = User::factory()->onboarded()->create();
    $otherUser = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Shared Bank', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Own Account',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
    Account::factory()->create([
        'user_id' => $otherUser->id,
        'bank_id' => $bank->id,
        'name' => 'Someone Elses Account',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded']);

    $page->waitForText('My Own Account')
        ->assertDontSee('Someone Elses Account')
        ->assertNoJavascriptErrors();
});
