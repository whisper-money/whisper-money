<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\RealEstateDetail;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

function createManualAccountTypeViaUi($page, string $displayName, string $bankName, string $type, string $currency = 'EUR', ?string $balance = null): void
{
    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->fill('#display_name', $displayName)
        ->click('[data-testid="bank-select"]')
        ->wait(0.5)
        ->fill('input[placeholder="Search bank..."]', $bankName)
        ->wait(0.5)
        ->click($bankName)
        ->click('button[name="type"]')
        ->wait(0.5)
        ->click("[role=\"option\"]:has-text(\"{$type}\")")
        ->wait(0.3)
        ->click('button[name="currency_code"]')
        ->wait(0.5)
        ->click("[role=\"option\"]:has-text(\"{$currency}\")")
        ->wait(0.3);

    if ($balance !== null) {
        $page->fill('#balance', $balance);
    }

    $page->click('[data-testid="submit-account"]')
        ->wait(2)
        ->assertNoJavascriptErrors();
}

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
    $bank = Bank::factory()->create(['name' => 'Test Bank', 'logo' => null]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'My Checking',
        'type' => 'checking',
        'currency_code' => 'USD',
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');
    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

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

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->assertSee('Create a bank account, loan, or property to track it manually')
        ->assertNoJavascriptErrors();
});

it('can create a new bank account', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'My Bank', 'logo' => null]);

    actingAs($user);

    $page = visit('/settings/accounts');

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

it('can create a loan account with balance and loan details', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Mortgage Bank', 'logo' => null]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->fill('#display_name', 'Home Mortgage')
        ->click('[data-testid="bank-select"]')
        ->wait(0.5)
        ->fill('input[placeholder="Search bank..."]', 'Mortgage Bank')
        ->wait(0.5)
        ->click('Mortgage Bank')
        ->click('button[name="type"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Loan")')
        ->wait(0.3)
        ->click('button[name="currency_code"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(0.3)
        ->fill('#balance', '180000')
        ->fill('#annual_interest_rate', '3.5')
        ->fill('#loan_term_months', '360')
        ->fill('#loan_start_date', '2024-01-01')
        ->fill('#original_amount', '250000')
        ->click('[data-testid="submit-account"]')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $loan = Account::query()
        ->where('user_id', $user->id)
        ->where('type', AccountType::Loan)
        ->first();

    expect($loan)->not->toBeNull();
    expect($loan->currency_code)->toBe('EUR');
    expect($loan->balances)->toHaveCount(1);
    expect($loan->balances->first()->balance)->toBe(18000000);
    expect($loan->loanDetail)->not->toBeNull();
    expect($loan->loanDetail->loan_term_months)->toBe(360);
    expect((string) $loan->loanDetail->annual_interest_rate)->toBe('3.500');
    expect($loan->loanDetail->original_amount)->toBe(25000000);
});

it('can create the remaining manual account types', function (string $typeLabel, AccountType $type, ?string $balance) {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Coverage Bank', 'logo' => null]);

    actingAs($user);

    $page = visit('/settings/accounts');

    createManualAccountTypeViaUi(
        $page,
        "{$typeLabel} Coverage Account",
        'Coverage Bank',
        $typeLabel,
        'EUR',
        $balance,
    );

    $account = Account::query()
        ->where('user_id', $user->id)
        ->where('type', $type)
        ->first();

    expect($account)->not->toBeNull();
    expect($account->currency_code)->toBe('EUR');

    if ($balance === null) {
        expect($account->balances)->toHaveCount(0);
    } else {
        expect($account->balances)->toHaveCount(1);
    }
})->with([
    'credit card' => ['Credit Card', AccountType::CreditCard, null],
    'investment' => ['Investment', AccountType::Investment, '125000'],
    'retirement' => ['Retirement / Pension', AccountType::Retirement, '250000'],
    'others' => ['Others', AccountType::Others, null],
]);

it('can create a real estate account linked to an existing loan', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('real-estate');

    $loanBank = Bank::factory()->create(['name' => 'Linked Mortgage Bank', 'logo' => null]);

    $loanAccount = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $loanBank->id,
        'name' => 'Primary Mortgage',
        'type' => AccountType::Loan,
        'currency_code' => 'EUR',
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');

    $page->assertSee('Bank accounts')
        ->click('Create Account')
        ->wait(0.5)
        ->fill('#display_name', 'City Apartment')
        ->click('button[name="type"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(0.3)
        ->click('button[name="currency_code"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(0.3)
        ->fill('#balance', '320000')
        ->click('button[name="property_type"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Residential")')
        ->wait(0.3)
        ->fill('#address', '123 Linked Street')
        ->fill('#purchase_price', '275000')
        ->fill('#purchase_date', '2024-02-01')
        ->fill('#area_value', '82')
        ->click('button[name="area_unit"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("m²")')
        ->wait(0.3)
        ->click('button[name="linked_loan_account_id"]')
        ->wait(0.5)
        ->click('[role="option"]:has-text("Primary Mortgage")')
        ->wait(0.3)
        ->fill('#notes', 'Main residence')
        ->fill('#revaluation_percentage', '2.5')
        ->click('[data-testid="submit-account"]')
        ->wait(2)
        ->assertNoJavascriptErrors();

    $account = Account::query()
        ->where('user_id', $user->id)
        ->where('type', AccountType::RealEstate)
        ->first();

    expect($account)->not->toBeNull();
    // Historical balances are generated from purchase_date (2024-02-01) to today
    expect($account->balances()->count())->toBeGreaterThan(1);
    expect($account->balances()->whereDate('balance_date', today())->first()->balance)->toBe(32000000);

    $detail = RealEstateDetail::query()
        ->where('account_id', $account->id)
        ->first();

    expect($detail)->not->toBeNull();
    expect($detail->linked_loan_account_id)->toBe($loanAccount->id);
    expect($detail->address)->toBe('123 Linked Street');
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
    $bank = Bank::factory()->create(['name' => 'Test Bank', 'logo' => null]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Checking Account',
    ]);
    Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Savings Account',
    ]);

    actingAs($user);

    $page = visit('/settings/accounts');
    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])->wait(2);

    $page->assertSee('Bank accounts')
        ->waitForText('Test Bank')
        ->fill('input[placeholder="Filter accounts..."]', 'Checking')
        ->wait(0.5)
        ->assertNoJavascriptErrors();
});

it('can edit an existing account via dropdown menu', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Edit Bank', 'logo' => null]);

    actingAs($user);

    $page = visit('/settings/accounts');

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'Old Account Name', 'Edit Bank', 'Checking', 'USD');

    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])->wait(5);

    $page->assertSee('Bank accounts')
        ->click('button[aria-label="Open menu"]')
        ->wait(0.5)
        ->click('Edit')
        ->wait(1)
        ->assertSee('Edit Account')
        ->fill('#display_name', 'Updated Account Name')
        ->click('button[type="submit"]:has-text("Update")')
        ->wait(2);

    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])->wait(5);

    $page->assertSee('Updated Account Name')
        ->assertNoJavascriptErrors();
});

it('can delete an account via dropdown menu', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Delete Bank', 'logo' => null]);

    actingAs($user);

    $page = visit('/settings/accounts');

    // Create account via UI to ensure it syncs to IndexedDB
    createAccountViaUI($page, 'Account To Delete', 'Delete Bank', 'Checking', 'USD');

    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])->wait(5);

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

    $page->navigate('/settings/accounts', ['waitUntil' => 'domcontentloaded'])->wait(5);

    $page->assertDontSee('Account To Delete')
        ->assertNoJavascriptErrors();
});
