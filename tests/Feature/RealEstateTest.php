<?php

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\RealEstateDetail;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->bank = Bank::factory()->create();
    Feature::for($this->user)->activate('real-estate');
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

it('requires bank_id for non-real-estate account types', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Checking Account',
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
        // bank_id intentionally omitted
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors(['bank_id']);
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
    ]);

    Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
        'name' => 'Beach House',
    ]);

    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'Main Account',
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
