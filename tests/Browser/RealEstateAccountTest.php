<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\RealEstateDetail;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    actingAs($this->user);
});

it('shows real estate fields when real estate type is selected', function () {
    $page = visit('/settings/accounts');

    $page->waitForText('Create Account')
        ->click('Create Account')
        ->waitForText('Manual', 5)
        ->click('Manual')
        ->wait(1)
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(1)
        ->assertSee('Property Type')
        ->assertSee('Purchase Price')
        ->assertSee('Purchase Date')
        ->assertSee('Annual Revaluation (%)')
        ->assertNoJavascriptErrors();
});

it('auto-calculates revaluation percentage from purchase data and current value', function () {
    $page = visit('/settings/accounts');

    $page->waitForText('Create Account')
        ->click('Create Account')
        ->waitForText('Manual', 5)
        ->click('Manual')
        ->wait(1)
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1);

    // Fill current value (balance) — source input for CAGR calculation
    $page->fill('#balance', '240000')
        ->keys('#balance', ['Tab'])
        ->wait(0.5);

    // Fill purchase price
    $page->fill('#purchase_price', '200000')
        ->keys('#purchase_price', ['Tab'])
        ->wait(0.5);

    // Fill purchase date (2 years ago)
    $page->fill('#purchase_date', date('Y-m-d', strtotime('-2 years')))
        ->keys('#purchase_date', ['Tab'])
        ->wait(1);

    // Revaluation % should be auto-filled by the CAGR effect
    $page->assertValueIsNot('#revaluation_percentage', '')
        ->assertNoJavascriptErrors();
});

it('manual revaluation percentage is preserved when balance changes', function () {
    $page = visit('/settings/accounts');

    $page->waitForText('Create Account')
        ->click('Create Account')
        ->waitForText('Manual', 5)
        ->click('Manual')
        ->wait(1)
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1);

    // Set purchase data so auto-calc fires initially
    $page->fill('#purchase_price', '200000')
        ->keys('#purchase_price', ['Tab'])
        ->wait(0.5)
        ->fill('#purchase_date', date('Y-m-d', strtotime('-2 years')))
        ->keys('#purchase_date', ['Tab'])
        ->wait(0.5)
        ->fill('#balance', '240000')
        ->keys('#balance', ['Tab'])
        ->wait(1);

    // Override revaluation % manually
    $page->clear('#revaluation_percentage')
        ->fill('#revaluation_percentage', '5.00')
        ->wait(0.5);

    // Change balance — manual override should survive
    $page->fill('#balance', '250000')
        ->keys('#balance', ['Tab'])
        ->wait(1);

    $page->assertValue('#revaluation_percentage', '5.00')
        ->assertNoJavascriptErrors();
});

it('creates real estate account and generates historical balances', function () {
    $purchaseDate = date('Y-m-d', strtotime('-14 months'));

    $page = visit('/settings/accounts');

    $page->waitForText('Create Account')
        ->click('Create Account')
        ->waitForText('Manual', 5)
        ->click('Manual')
        ->wait(1)
        ->fill('#display_name', 'My Investment Property')
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1)
        ->click('Select property type')
        ->wait(1)
        ->click('[role="option"]:has-text("Residential")')
        ->wait(1);

    $page->fill('#balance', '240000')
        ->keys('#balance', ['Tab'])
        ->wait(0.5)
        ->fill('#purchase_price', '200000')
        ->keys('#purchase_price', ['Tab'])
        ->wait(0.5)
        ->fill('#purchase_date', $purchaseDate)
        ->keys('#purchase_date', ['Tab'])
        ->wait(1);

    $page->click('Create')
        ->wait(3)
        ->waitForText('My Investment Property')
        ->assertNoJavascriptErrors();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    expect($account)->not->toBeNull();
    // 14 months back → purchase date + ~14 month-starts + today = >2 balances
    expect($account->balances()->count())->toBeGreaterThan(2);
});

it('balance chart shows historical data after account creation', function () {
    $purchaseDate = Carbon::today()->subMonths(6)->toDateString();

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'purchase_price' => 20000000,
        'purchase_date' => $purchaseDate,
    ]);

    // Seed historical balances so the chart has data
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $purchaseDate,
        'balance' => 20000000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => Carbon::today()->toDateString(),
        'balance' => 24000000,
    ]);

    $page = visit('/accounts/'.$account->id);

    $page->waitForText('Market value evolution')
        ->assertDontSee('No market value data available')
        ->assertNoJavascriptErrors();
});

it('edit dialog pre-fills purchase date in correct format', function () {
    $purchaseDate = '2023-06-15';

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'purchase_date' => $purchaseDate,
    ]);

    $page = visit('/settings/accounts');

    $page->waitForText($account->name)
        ->click('[aria-label="Open menu"]')
        ->wait(1)
        ->click('Edit')
        ->waitForText('Edit Account', 5)
        ->assertValue('#purchase_date', $purchaseDate)
        ->assertNoJavascriptErrors();
});

it('redirects back to settings after creating an account', function () {
    $page = visit('/settings/accounts');

    $page->waitForText('Create Account')
        ->click('Create Account')
        ->waitForText('Manual', 5)
        ->click('Manual')
        ->wait(1)
        ->fill('#display_name', 'Test Redirect Account')
        ->click('Select account type')
        ->wait(1)
        ->click('[role="option"]:has-text("Real Estate")')
        ->wait(1)
        ->click('Select currency')
        ->wait(1)
        ->click('[role="option"]:has-text("EUR")')
        ->wait(1)
        ->click('Select property type')
        ->wait(1)
        ->click('[role="option"]:has-text("Residential")')
        ->wait(1)
        ->click('Create')
        ->wait(3)
        ->assertPathIs('/settings/accounts')
        ->assertNoJavascriptErrors();
});
