<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('can view accounts page', function () {
    $user = User::factory()->onboarded()->create();

    actingAs($user);

    $page = visit('/accounts');

    $page->assertSee('Accounts')
        ->assertSee('View and manage your bank accounts')
        ->assertNoJavascriptErrors();
});

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
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->assertSee('Accounts')
        ->waitForText('My Checking')
        ->assertSee('Test Bank')
        ->assertNoJavascriptErrors();
});

it('shows multiple accounts grouped by type', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Bank One', 'logo' => null]);

    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Daily Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Rainy Day Savings',
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/accounts');
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->assertSee('Accounts')
        ->waitForText('Daily Checking')
        ->assertSee('Rainy Day Savings')
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
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->waitForText('Navigable Account')
        ->click('Details →')
        ->wait(2)
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
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->waitForText('Tracked Checking')
        ->click('Details →')
        ->wait(2)
        ->assertSee('Import balances')
        ->click('Update balance')
        ->wait(1)
        ->fill('#balance-amount', '120000.00')
        ->click('#balance-date')
        ->fill('#balance-date', '2024-02-01')
        ->click('button[type="submit"]:has-text("Save")')
        ->wait(2)
        ->click('button[aria-label="More options"]')
        ->wait(0.5)
        ->click('See balances')
        ->wait(2)
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
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->waitForText('Beach Condo')
        ->click('Details →')
        ->wait(2)
        ->assertSee('Property Details')
        ->assertDontSee('Linked Mortgage / Loan')
        ->click('Edit')
        ->wait(1)
        ->assertSee('Edit Property Details')
        ->click('xpath=//label[contains(., "Linked Mortgage / Loan")]/following::button[@role="combobox"][1]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Beach Mortgage")')
        ->wait(0.5)
        ->click('button[type="submit"]:has-text("Save")')
        ->wait(2)
        ->assertSee('Linked Mortgage / Loan')
        ->assertSee('Beach Mortgage (Mortgage Bank)')
        ->click('Edit')
        ->wait(1)
        ->click('xpath=//label[contains(., "Linked Mortgage / Loan")]/following::button[@role="combobox"][1]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("No linked loan")')
        ->wait(0.5)
        ->click('button[type="submit"]:has-text("Save")')
        ->wait(2)
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
    $page->navigate('/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->waitForText('My Own Account')
        ->assertDontSee('Someone Elses Account')
        ->assertNoJavascriptErrors();
});
