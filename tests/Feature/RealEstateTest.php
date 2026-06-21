<?php

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Jobs\GenerateHistoricalRealEstateBalancesJob;
use App\Models\Account;
use App\Models\Bank;
use App\Models\RealEstateDetail;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->bank = Bank::factory()->create();
});

// -------------------------------------------------------------------
// Creating real estate accounts via Settings\AccountController@store
// -------------------------------------------------------------------

it('can create a real estate account with property details', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My Apartment',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'address' => '123 Main St, Madrid',
        'purchase_price' => 25000000, // 250,000.00 in cents
        'purchase_date' => '2023-06-15',
        'area_value' => 120.50,
        'area_unit' => 'sqm',
        'notes' => 'First floor, two bedrooms',
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'name' => 'My Apartment',
        'type' => AccountType::RealEstate->value,
        'currency_code' => 'EUR',
        'bank_id' => null,
    ]);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'property_type' => PropertyType::Residential->value,
        'address' => '123 Main St, Madrid',
        'purchase_price' => 25000000,
        'area_unit' => 'sqm',
        'notes' => 'First floor, two bedrooms',
    ]);
});

it('can create a real estate account with only required fields', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Vacant Lot',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Land->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'property_type' => PropertyType::Land->value,
    ]);
});

it('requires property_type when creating a real estate account', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        // property_type is missing
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors(['property_type']);
});

it('validates property_type must be a valid enum value', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => 'castle',
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors(['property_type']);
});

it('can create a real estate account with a linked loan', function () {
    actingAs($this->user);

    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'type' => AccountType::Loan,
    ]);

    $data = [
        'name' => 'House with Mortgage',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'linked_loan_account_id' => $loanAccount->id,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);
});

it('validates linked_loan_account_id must be a loan account owned by the user', function () {
    actingAs($this->user);

    // Non-loan account owned by user
    $checkingAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    $data = [
        'name' => 'My House',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'linked_loan_account_id' => $checkingAccount->id,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors(['linked_loan_account_id']);
});

it('validates linked_loan_account_id cannot be another users loan', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();
    $otherLoan = Account::factory()->create([
        'user_id' => $otherUser->id,
        'type' => AccountType::Loan,
    ]);

    $data = [
        'name' => 'My House',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'linked_loan_account_id' => $otherLoan->id,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors(['linked_loan_account_id']);
});

it('does not require property_type for non-real-estate accounts', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Checking Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();
});

it('does not require bank_id for non-real-estate account types', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Checking Account',
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
        // bank_id intentionally omitted
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionMissing('errors');
});

// -------------------------------------------------------------------
// Account show page loads real estate data
// -------------------------------------------------------------------

it('loads real estate detail on account show page', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'property_type' => PropertyType::Residential,
        'address' => '456 Oak Ave',
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.real_estate_detail')
            ->where('account.real_estate_detail.property_type', PropertyType::Residential->value)
            ->where('account.real_estate_detail.address', '456 Oak Ave')
        );
});

it('loads available loan accounts on real estate show page', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
    ]);

    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
    ]);

    // Another user's loan should not be available
    Account::factory()->create([
        'user_id' => User::factory()->create()->id,
        'type' => AccountType::Loan,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.available_loan_accounts', 1)
            ->where('account.available_loan_accounts.0.id', $loanAccount->id)
        );
});

it('loads linked loan account with bank info on show page', function () {
    $this->withoutVite();
    actingAs($this->user);

    $loanBank = Bank::factory()->create(['name' => 'Mortgage Bank']);
    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $loanBank->id,
        'type' => AccountType::Loan,
    ]);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.real_estate_detail.linked_loan_account')
            ->where('account.real_estate_detail.linked_loan_account.id', $loanAccount->id)
            ->where('account.real_estate_detail.linked_loan_account.bank.name', 'Mortgage Bank')
        );
});

it('does not load real estate data for non-real-estate accounts', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->missing('account.real_estate_detail')
            ->missing('account.available_loan_accounts')
        );
});

// -------------------------------------------------------------------
// Accounts index includes real estate in ordering
// -------------------------------------------------------------------

it('includes real estate accounts in index ordered correctly', function () {
    $this->withoutVite();
    actingAs($this->user);

    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'name' => 'Mortgage',
        'position' => 2,
    ]);

    Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
        'name' => 'Beach House',
        'position' => 1,
    ]);

    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'Main Account',
        'position' => 0,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
            ->where('accounts.0.type', 'checking')
            ->where('accounts.1.type', 'real_estate')
            ->where('accounts.2.type', 'loan')
        );
});

// -------------------------------------------------------------------
// Updating real estate details via RealEstateDetailController
// -------------------------------------------------------------------

it('can update real estate detail', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $detail = RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'property_type' => PropertyType::Residential,
        'address' => 'Old Address',
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'property_type' => PropertyType::Commercial->value,
        'address' => 'New Commercial Address',
        'purchase_price' => 50000000,
        'notes' => 'Updated notes',
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('real_estate_details', [
        'id' => $detail->id,
        'property_type' => PropertyType::Commercial->value,
        'address' => 'New Commercial Address',
        'purchase_price' => 50000000,
        'notes' => 'Updated notes',
    ]);
});

it('can link a loan account when updating real estate detail', function () {
    actingAs($this->user);

    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
    ]);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'linked_loan_account_id' => $loanAccount->id,
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);
});

it('can unlink a loan account by setting null', function () {
    actingAs($this->user);

    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
    ]);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'linked_loan_account_id' => null,
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'linked_loan_account_id' => null,
    ]);
});

it('validates linked_loan_account_id on update must be users loan', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();
    $otherLoan = Account::factory()->create([
        'user_id' => $otherUser->id,
        'type' => AccountType::Loan,
    ]);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'linked_loan_account_id' => $otherLoan->id,
    ]);

    $response->assertSessionHasErrors(['linked_loan_account_id']);
});

it('returns 404 when updating real estate detail for account without one', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    // No RealEstateDetail created

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'property_type' => PropertyType::Commercial->value,
    ]);

    $response->assertNotFound();
});

// -------------------------------------------------------------------
// IDOR protection for real estate detail updates
// -------------------------------------------------------------------

it('prevents updating another users real estate detail', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->realEstate()->create([
        'user_id' => $otherUser->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $otherAccount->id,
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $otherAccount), [
        'address' => 'Hacked Address',
    ]);

    $response->assertForbidden();
});

// -------------------------------------------------------------------
// Model relationships
// -------------------------------------------------------------------

it('has a one-to-one relationship between account and real estate detail', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $detail = RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'property_type' => PropertyType::Vacation,
    ]);

    expect($account->fresh()->realEstateDetail)->not->toBeNull();
    expect($account->fresh()->realEstateDetail->id)->toBe($detail->id);
    expect($detail->fresh()->account->id)->toBe($account->id);
});

it('can link and access a loan account through real estate detail', function () {
    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
    ]);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $detail = RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);

    expect($detail->fresh()->linkedLoanAccount)->not->toBeNull();
    expect($detail->fresh()->linkedLoanAccount->id)->toBe($loanAccount->id);
    expect($detail->fresh()->linkedLoanAccount->type)->toBe(AccountType::Loan);
});

// -------------------------------------------------------------------
// Deleting an account cascades to real estate detail
// -------------------------------------------------------------------

it('preserves real estate detail when account is soft deleted', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $detail = RealEstateDetail::factory()->create([
        'account_id' => $account->id,
    ]);

    $this->delete(route('accounts.destroy', $account));

    // Account is soft-deleted
    expect(Account::find($account->id))->toBeNull();
    expect(Account::withTrashed()->find($account->id))->not->toBeNull();

    // Real estate detail still exists (FK cascade only applies to hard deletes)
    assertDatabaseHas('real_estate_details', ['id' => $detail->id]);
});

// -------------------------------------------------------------------
// Creating real estate accounts with balance and revaluation percentage
// -------------------------------------------------------------------

it('can create a real estate account with initial market value', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'balance' => 30000000, // 300,000.00 in cents
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 30000000,
        'balance_date' => now()->toDateString(),
    ]);
});

it('can create a real estate account with revaluation percentage', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Appreciating Property',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'balance' => 50000000,
        'revaluation_percentage' => 3.50,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'revaluation_percentage' => '3.50',
    ]);

    assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 50000000,
    ]);
});

it('can create a real estate account with negative revaluation percentage', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Depreciating Property',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Commercial->value,
        'revaluation_percentage' => -2.00,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'revaluation_percentage' => '-2.00',
    ]);
});

it('validates revaluation percentage is between -100 and 100', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'revaluation_percentage' => 150,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors(['revaluation_percentage']);
});

it('can update revaluation percentage via real estate detail endpoint', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    $detail = RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => null,
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'revaluation_percentage' => 5.25,
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('real_estate_details', [
        'id' => $detail->id,
        'revaluation_percentage' => '5.25',
    ]);
});

it('can clear revaluation percentage by setting null', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 3.50,
    ]);

    $response = $this->patch(route('accounts.real-estate-detail.update', $account), [
        'revaluation_percentage' => null,
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'revaluation_percentage' => null,
    ]);
});

// -------------------------------------------------------------------
// Show page returns revaluation_percentage and purchase_date correctly
// -------------------------------------------------------------------

it('loads revaluation_percentage and purchase_date on account show page', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'property_type' => PropertyType::Residential,
        'purchase_date' => '2023-06-15',
        'revaluation_percentage' => 5.25,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.real_estate_detail')
            ->where('account.real_estate_detail.revaluation_percentage', '5.25')
            ->where('account.real_estate_detail.purchase_date', '2023-06-15')
        );
});

// -------------------------------------------------------------------
// Updating real estate accounts via Settings\AccountController@update
// -------------------------------------------------------------------

it('can update real estate details via account update endpoint', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'property_type' => PropertyType::Residential,
        'address' => 'Old Address',
        'purchase_date' => '2020-01-01',
        'revaluation_percentage' => 2.00,
    ]);

    $response = $this->patch(route('accounts.update', $account), [
        'name' => 'Updated Property',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Commercial->value,
        'address' => 'New Address',
        'purchase_price' => 30000000,
        'purchase_date' => '2023-06-15',
        'revaluation_percentage' => 4.50,
    ]);

    $response->assertRedirect(route('accounts.index'));

    assertDatabaseHas('accounts', [
        'id' => $account->id,
        'name' => 'Updated Property',
    ]);

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'property_type' => PropertyType::Commercial->value,
        'address' => 'New Address',
        'purchase_price' => 30000000,
        'revaluation_percentage' => '4.50',
    ]);
});

it('creates real estate detail via account update if it does not exist', function () {
    actingAs($this->user);

    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    // No RealEstateDetail created yet

    $response = $this->patch(route('accounts.update', $account), [
        'name' => 'New Property',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Land->value,
        'purchase_date' => '2024-01-01',
        'revaluation_percentage' => 1.50,
    ]);

    $response->assertRedirect(route('accounts.index'));

    assertDatabaseHas('real_estate_details', [
        'account_id' => $account->id,
        'property_type' => PropertyType::Land->value,
        'revaluation_percentage' => '1.50',
    ]);
});

// -------------------------------------------------------------------
// Historical balance generation on account creation
// -------------------------------------------------------------------

it('generates historical balances when creating real estate account with purchase data', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15'));

    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'purchase_price' => 20000000, // 200,000.00
        'purchase_date' => '2025-11-15',
        'balance' => 24000000, // 240,000.00 (current value)
    ];

    $response = $this->post(route('accounts.store'), $data);
    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    $balances = $account->balances()->orderBy('balance_date')->get();

    // Expect: purchase date, Dec 1, Jan 1, Feb 1, Mar 1, and today (Mar 15)
    expect($balances)->toHaveCount(6);

    // First balance should be the purchase price on the purchase date
    expect($balances[0]->balance_date->toDateString())->toBe('2025-11-15');
    expect($balances[0]->balance)->toBe(20000000);

    // Last balance should be the current value on today
    expect($balances[5]->balance_date->toDateString())->toBe('2026-03-15');
    expect($balances[5]->balance)->toBe(24000000);

    // Intermediate balances should be linearly interpolated
    // Total days: Nov 15 to Mar 15 = 120 days
    $totalDays = 120;

    // Dec 1: 16 days elapsed
    expect($balances[1]->balance_date->toDateString())->toBe('2025-12-01');
    expect($balances[1]->balance)->toBe((int) round(20000000 + 4000000 * (16 / $totalDays)));

    // Jan 1: 47 days elapsed
    expect($balances[2]->balance_date->toDateString())->toBe('2026-01-01');
    expect($balances[2]->balance)->toBe((int) round(20000000 + 4000000 * (47 / $totalDays)));

    // Feb 1: 78 days elapsed
    expect($balances[3]->balance_date->toDateString())->toBe('2026-02-01');
    expect($balances[3]->balance)->toBe((int) round(20000000 + 4000000 * (78 / $totalDays)));

    // Mar 1: 106 days elapsed
    expect($balances[4]->balance_date->toDateString())->toBe('2026-03-01');
    expect($balances[4]->balance)->toBe((int) round(20000000 + 4000000 * (106 / $totalDays)));
});

it('does not generate historical balances when purchase_price is missing', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'purchase_date' => '2025-06-01',
        'balance' => 30000000,
    ];

    $this->post(route('accounts.store'), $data);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    // Only today's balance should exist (from the regular balance creation)
    expect($account->balances)->toHaveCount(1);
    expect($account->balances->first()->balance)->toBe(30000000);
});

it('does not generate historical balances when purchase_date is missing', function () {
    actingAs($this->user);

    $data = [
        'name' => 'My House',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'purchase_price' => 20000000,
        'balance' => 30000000,
    ];

    $this->post(route('accounts.store'), $data);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    // Only today's balance should exist
    expect($account->balances)->toHaveCount(1);
    expect($account->balances->first()->balance)->toBe(30000000);
});

it('handles purchase_date equal to today when generating historical balances', function () {
    actingAs($this->user);

    $data = [
        'name' => 'New Purchase',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Land->value,
        'purchase_price' => 15000000,
        'purchase_date' => now()->toDateString(),
        'balance' => 15000000,
    ];

    $this->post(route('accounts.store'), $data);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    // Only one balance for today
    expect($account->balances)->toHaveCount(1);
    expect($account->balances->first()->balance_date->toDateString())->toBe(now()->toDateString());
    expect($account->balances->first()->balance)->toBe(15000000);
});

it('generates flat balances when purchase_price equals current value', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15'));

    actingAs($this->user);

    $data = [
        'name' => 'Flat Value Property',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Commercial->value,
        'purchase_price' => 50000000,
        'purchase_date' => '2026-01-01',
        'balance' => 50000000,
    ];

    $this->post(route('accounts.store'), $data);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    $balances = $account->balances()->orderBy('balance_date')->get();

    // All balances should be the same value
    foreach ($balances as $balance) {
        expect($balance->balance)->toBe(50000000);
    }
});

it('does not generate historical balances when balance is not provided', function () {
    actingAs($this->user);

    $data = [
        'name' => 'No Balance Property',
        'currency_code' => 'USD',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'purchase_price' => 20000000,
        'purchase_date' => '2025-06-01',
    ];

    $this->post(route('accounts.store'), $data);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    // No balances should be created
    expect($account->balances)->toHaveCount(0);
});

it('dispatches a job for older balances when purchase predates 12-month window', function () {
    Bus::fake(GenerateHistoricalRealEstateBalancesJob::class);

    $this->travelTo(Carbon\Carbon::parse('2026-03-15'));

    actingAs($this->user);

    $data = [
        'name' => 'Old Property',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'purchase_price' => 10000000,
        'purchase_date' => '2020-06-15', // ~6 years ago
        'balance' => 18000000,
    ];

    $response = $this->post(route('accounts.store'), $data);
    $response->assertRedirect();

    Bus::assertDispatched(GenerateHistoricalRealEstateBalancesJob::class, function ($job) {
        return $job->purchaseDate->toDateString() === '2020-06-15'
            && $job->purchasePrice === 10000000
            && $job->currentValue === 18000000;
    });

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::RealEstate->value)
        ->first();

    // Only the last 12 months of balances should exist synchronously
    $balances = $account->balances()->orderBy('balance_date')->get();
    $twelveMonthsAgo = Carbon\Carbon::parse('2025-03-01');

    foreach ($balances as $balance) {
        expect($balance->balance_date->gte($twelveMonthsAgo))->toBeTrue();
    }

    // Today's balance should be the current value
    $todayBalance = $balances->last();
    expect($todayBalance->balance_date->toDateString())->toBe('2026-03-15');
    expect($todayBalance->balance)->toBe(18000000);
});

it('does not dispatch a job when purchase is within 12-month window', function () {
    Bus::fake(GenerateHistoricalRealEstateBalancesJob::class);

    $this->travelTo(Carbon\Carbon::parse('2026-03-15'));

    actingAs($this->user);

    $data = [
        'name' => 'Recent Property',
        'currency_code' => 'EUR',
        'type' => AccountType::RealEstate->value,
        'property_type' => PropertyType::Residential->value,
        'purchase_price' => 20000000,
        'purchase_date' => '2025-11-15', // within last 12 months
        'balance' => 24000000,
    ];

    $this->post(route('accounts.store'), $data);

    Bus::assertNotDispatched(GenerateHistoricalRealEstateBalancesJob::class);
});
