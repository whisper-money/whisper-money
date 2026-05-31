<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\User;
use App\Services\LoanAmortizationService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->bank = Bank::factory()->create();
    $this->service = app(LoanAmortizationService::class);
});

// -------------------------------------------------------------------
// LoanAmortizationService — methods requiring Eloquent models (DB)
// -------------------------------------------------------------------

it('calculates remaining months correctly', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(24),
        'loan_term_months' => 360,
    ]);

    $remaining = $this->service->calculateRemainingMonths($loanDetail, now());

    expect($remaining)->toBe(336);
});

it('returns zero remaining months when loan is past term', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(400),
        'loan_term_months' => 360,
    ]);

    $remaining = $this->service->calculateRemainingMonths($loanDetail, now());

    expect($remaining)->toBe(0);
});

it('calculates balance at a specific date from loan details', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(12),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    $balance = $this->service->getBalanceAtDate($loanDetail, now());

    // After 12 months of a $200k loan at 3.5%, should still owe ~$195k-$198k
    expect($balance)->toBeGreaterThan(19500000)
        ->toBeLessThan(19800000);
});

it('returns original amount for balance before loan start', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->addMonths(6),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    $balance = $this->service->getBalanceAtDate($loanDetail, now());

    expect($balance)->toBe(20000000);
});

it('returns zero for balance after loan term ends', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(400),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    $balance = $this->service->getBalanceAtDate($loanDetail, now());

    expect($balance)->toBe(0);
});

it('projects from existing balance entries instead of original loan params', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(138),
        'loan_term_months' => 333,
        'annual_interest_rate' => 4.110,
        'original_amount' => 7991346,
    ]);

    // Simulate a real balance that's lower than the theoretical amortization schedule
    // (e.g. the user has been making extra payments)
    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'balance' => 4489670,
    ]);

    $balance = $this->service->getBalanceAtDate($loanDetail, now());

    // The result should project from 4,489,670, NOT recalculate from original 7,991,346.
    // Theoretical from original params would give ~5,700,000+ which is wrong.
    expect($balance)->toBeLessThan(4489670)
        ->toBeGreaterThan(4400000);
});

it('returns existing balance when target date is in the same month', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(12),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->toDateString(),
        'balance' => 19500000,
    ]);

    $balance = $this->service->getBalanceAtDate($loanDetail, now());

    expect($balance)->toBe(19500000);
});

it('generates correct monthly balance when existing entries differ from theoretical', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(138),
        'loan_term_months' => 333,
        'annual_interest_rate' => 4.110,
        'original_amount' => 7991346,
    ]);

    // Balance from last month that's lower than theoretical schedule
    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'balance' => 4489670,
    ]);

    artisan('loans:generate-balances')->assertSuccessful();

    $generated = AccountBalance::query()
        ->where('account_id', $account->id)
        ->where('balance_date', now()->startOfMonth()->toDateString())
        ->first();

    expect($generated)->not->toBeNull();

    // The generated balance should be projected from the real 4,489,670
    // and should be slightly lower (one month of amortization), not jump up
    expect($generated->balance)->toBeLessThan(4489670)
        ->toBeGreaterThan(4400000);
});

it('generates projection from last account balance entry', function () {
    $account = Account::factory()->loan()->create(['user_id' => $this->user->id, 'bank_id' => $this->bank->id]);
    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(12),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'balance' => 19700000,
    ]);

    $projection = $this->service->generateProjection($loanDetail, 6);

    expect($projection)->not->toBeEmpty()
        ->and(count($projection))->toBeLessThanOrEqual(6);

    // Each projected month should be decreasing
    $values = array_values($projection);
    for ($i = 1; $i < count($values); $i++) {
        expect($values[$i])->toBeLessThan($values[$i - 1]);
    }
});

// -------------------------------------------------------------------
// Creating loan accounts via Settings\AccountController@store
// -------------------------------------------------------------------

it('can create a loan account with loan details', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Home Mortgage',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
        'loan_start_date' => '2024-01-15',
        'original_amount' => 20000000,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    assertDatabaseHas('accounts', [
        'user_id' => $this->user->id,
        'name' => 'Home Mortgage',
        'type' => AccountType::Loan->value,
    ]);

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::Loan->value)
        ->first();

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'annual_interest_rate' => 3.500,
        'loan_term_months' => 360,
        'start_date' => '2024-01-15',
        'original_amount' => 20000000,
    ]);
});

it('can create a loan account linked to an existing unlinked real estate account', function () {
    actingAs($this->user);

    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'USD',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
    ]);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Mortgage Loan',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'linked_real_estate_account_id' => $realEstateAccount->id,
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
        'original_amount' => 20000000,
    ]);

    $response->assertRedirect();

    $loanAccount = Account::query()
        ->where('user_id', $this->user->id)
        ->where('type', AccountType::Loan->value)
        ->where('name', 'Mortgage Loan')
        ->first();

    expect($loanAccount)->not->toBeNull();

    assertDatabaseHas('real_estate_details', [
        'account_id' => $realEstateAccount->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);
});

it('validates linked_real_estate_account_id must be a real estate account owned by the user', function () {
    actingAs($this->user);

    $checkingAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Mortgage Loan',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'linked_real_estate_account_id' => $checkingAccount->id,
    ]);

    $response->assertSessionHasErrors(['linked_real_estate_account_id']);
});

it('validates linked_real_estate_account_id cannot be another users property', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();
    $otherRealEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $otherUser->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $otherRealEstateAccount->id,
    ]);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Mortgage Loan',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'linked_real_estate_account_id' => $otherRealEstateAccount->id,
    ]);

    $response->assertSessionHasErrors(['linked_real_estate_account_id']);
});

it('validates linked_real_estate_account_id cannot be an already linked property', function () {
    actingAs($this->user);

    $existingLoan = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
        'linked_loan_account_id' => $existingLoan->id,
    ]);

    $response = $this->post(route('accounts.store'), [
        'name' => 'Mortgage Loan',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'linked_real_estate_account_id' => $realEstateAccount->id,
    ]);

    $response->assertSessionHasErrors(['linked_real_estate_account_id']);
});

it('can create a loan account without loan details', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Simple Loan',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();

    $account = Account::query()
        ->where('user_id', $this->user->id)
        ->where('name', 'Simple Loan')
        ->first();

    expect($account)->not->toBeNull();

    assertDatabaseMissing('loan_details', [
        'account_id' => $account->id,
    ]);
});

it('validates loan fields when creating a loan account', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Bad Loan',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'annual_interest_rate' => 150, // over 100
        'loan_term_months' => 700, // over 600
        'original_amount' => -100, // negative
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertSessionHasErrors([
        'annual_interest_rate',
        'loan_term_months',
        'original_amount',
    ]);
});

it('does not apply loan validation rules for non-loan accounts', function () {
    actingAs($this->user);

    $data = [
        'name' => 'Checking Account',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
    ];

    $response = $this->post(route('accounts.store'), $data);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors();
});

// -------------------------------------------------------------------
// Updating loan accounts via Settings\AccountController@update
// -------------------------------------------------------------------

it('can update a loan account with loan details', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $data = [
        'name' => 'Updated Mortgage',
        'bank_id' => $this->bank->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'annual_interest_rate' => 4.25,
        'loan_term_months' => 240,
        'loan_start_date' => '2023-06-01',
        'original_amount' => 15000000,
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'annual_interest_rate' => 4.250,
        'loan_term_months' => 240,
        'start_date' => '2023-06-01',
        'original_amount' => 15000000,
    ]);
});

it('can update existing loan detail via account update', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
        'original_amount' => 20000000,
    ]);

    $data = [
        'name' => $account->name,
        'bank_id' => $this->bank->id,
        'currency_code' => $account->currency_code,
        'type' => AccountType::Loan->value,
        'annual_interest_rate' => 5.0,
        'loan_term_months' => 360,
        'original_amount' => 20000000,
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'annual_interest_rate' => 5.000,
    ]);

    // Should only have one loan detail record
    expect(LoanDetail::where('account_id', $account->id)->count())->toBe(1);
});

it('creates loan detail when updating a loan account with all required fields and no existing loan detail', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    // No loan detail exists — simulate the edit dialog filling in all fields
    $data = [
        'name' => $account->name,
        'bank_id' => $this->bank->id,
        'currency_code' => $account->currency_code,
        'type' => AccountType::Loan->value,
        'annual_interest_rate' => '3.5',
        'loan_term_months' => '360',
        'loan_start_date' => '2026-01-01',
        'original_amount' => 200000,
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'annual_interest_rate' => 3.500,
        'loan_term_months' => 360,
        'start_date' => '2026-01-01',
        'original_amount' => 200000,
    ]);
});

it('does not crash when updating a loan account with partial loan data and no existing loan detail', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $data = [
        'name' => $account->name,
        'bank_id' => $this->bank->id,
        'currency_code' => $account->currency_code,
        'type' => AccountType::Loan->value,
        'loan_start_date' => '2011-09-23',
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));
    $response->assertSessionHasErrors(['annual_interest_rate', 'loan_term_months', 'original_amount']);

    // Should not have created a loan detail with incomplete data
    assertDatabaseMissing('loan_details', [
        'account_id' => $account->id,
    ]);
});

it('can partially update existing loan detail via account update', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
        'start_date' => '2020-01-01',
        'original_amount' => 20000000,
    ]);

    $data = [
        'name' => $account->name,
        'bank_id' => $this->bank->id,
        'currency_code' => $account->currency_code,
        'type' => AccountType::Loan->value,
        'loan_start_date' => '2011-09-23',
    ];

    $response = $this->patch(route('accounts.update', $account), $data);

    $response->assertRedirect(route('accounts.index'));

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'start_date' => '2011-09-23',
        'annual_interest_rate' => 3.500,
        'loan_term_months' => 360,
        'original_amount' => 20000000,
    ]);
});

// -------------------------------------------------------------------
// LoanDetailController — updating loan details from show page
// -------------------------------------------------------------------

it('can update loan detail via loan detail controller', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
    ]);

    $response = $this->patch(route('accounts.loan-detail.update', $account), [
        'annual_interest_rate' => 4.75,
        'loan_term_months' => 300,
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'annual_interest_rate' => 4.750,
        'loan_term_months' => 300,
    ]);
});

it('can create loan detail via loan detail controller when none exists', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->patch(route('accounts.loan-detail.update', $account), [
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
        'start_date' => '2024-01-01',
        'original_amount' => 20000000,
    ]);

    $response->assertRedirect(route('accounts.show', $account));

    assertDatabaseHas('loan_details', [
        'account_id' => $account->id,
        'annual_interest_rate' => 3.500,
        'loan_term_months' => 360,
        'start_date' => '2024-01-01',
        'original_amount' => 20000000,
    ]);
});

it('does not crash when creating loan detail via loan detail controller with partial data', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->patch(route('accounts.loan-detail.update', $account), [
        'start_date' => '2024-01-01',
    ]);

    $response->assertRedirect(route('accounts.show', $account));
    $response->assertSessionHasErrors(['annual_interest_rate', 'loan_term_months', 'original_amount']);

    assertDatabaseMissing('loan_details', [
        'account_id' => $account->id,
    ]);
});

it('validates loan detail fields on update', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create(['account_id' => $account->id]);

    $response = $this->patch(route('accounts.loan-detail.update', $account), [
        'annual_interest_rate' => 200, // over 100
        'loan_term_months' => -5, // negative
    ]);

    $response->assertSessionHasErrors(['annual_interest_rate', 'loan_term_months']);
});

it('prevents updating another users loan detail', function () {
    actingAs($this->user);

    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->loan()->create([
        'user_id' => $otherUser->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create(['account_id' => $otherAccount->id]);

    $response = $this->patch(route('accounts.loan-detail.update', $otherAccount), [
        'annual_interest_rate' => 1.0,
    ]);

    $response->assertForbidden();
});

// -------------------------------------------------------------------
// Account show page loads loan data
// -------------------------------------------------------------------

it('loads loan detail on account show page', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'annual_interest_rate' => 3.5,
        'loan_term_months' => 360,
        'start_date' => now()->subMonths(12),
        'original_amount' => 20000000,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.loan_detail')
            ->where('account.loan_detail.annual_interest_rate', '3.500')
            ->where('account.loan_detail.loan_term_months', 360)
            ->where('account.loan_detail.original_amount', 20000000)
            ->has('account.loan_detail.monthly_payment')
            ->has('account.loan_detail.remaining_months')
        );
});

it('does not load loan detail for non-loan accounts', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'type' => AccountType::Checking,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->missing('account.loan_detail')
        );
});

it('calculates monthly payment from current balance when balance entries exist', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $loanDetail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'annual_interest_rate' => 4.110,
        'loan_term_months' => 333,
        'start_date' => now()->subMonths(138),
        'original_amount' => 7991346,
    ]);

    // Simulate an early payment reducing the balance below the amortization schedule
    AccountBalance::create([
        'account_id' => $account->id,
        'balance' => 4489670,
        'balance_date' => now()->subDays(5),
    ]);

    $service = app(LoanAmortizationService::class);
    $remainingMonths = $service->calculateRemainingMonths($loanDetail, now());

    $expectedPayment = $service->calculateMonthlyPayment(
        4489670,
        4.110,
        $remainingMonths,
    );

    $originalPayment = $service->calculateMonthlyPayment(
        7991346,
        4.110,
        333,
    );

    // The payment based on current balance should be less than original
    expect($expectedPayment)->toBeLessThan($originalPayment);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->where('account.loan_detail.monthly_payment', $expectedPayment)
        );
});

it('shows loan account without loan detail gracefully', function () {
    $this->withoutVite();
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->missing('account.loan_detail')
        );
});

// -------------------------------------------------------------------
// GenerateMonthlyLoanBalances command
// -------------------------------------------------------------------

it('generates monthly balance entries for loan accounts', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(12),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    artisan('loans:generate-balances')->assertSuccessful();

    assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->toDateString(),
    ]);
});

it('skips loan accounts that already have a balance for current month', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(12),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->toDateString(),
        'balance' => 19500000,
    ]);

    artisan('loans:generate-balances')->assertSuccessful();

    // Should still have only one balance entry, not duplicated
    expect(AccountBalance::where('account_id', $account->id)->count())->toBe(1);

    // Original balance should be unchanged
    assertDatabaseHas('account_balances', [
        'account_id' => $account->id,
        'balance' => 19500000,
    ]);
});

it('skips loan accounts without loan details', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    artisan('loans:generate-balances')->assertSuccessful();

    assertDatabaseMissing('account_balances', [
        'account_id' => $account->id,
    ]);
});

// -------------------------------------------------------------------
// Projection API endpoint
// -------------------------------------------------------------------

it('returns projected data for loan accounts in balance evolution API', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => now()->subMonths(12),
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20000000,
    ]);

    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'balance' => 19700000,
    ]);

    $from = now()->subMonths(6)->toDateString();
    $to = now()->toDateString();

    $response = $this->getJson("/api/dashboard/account/{$account->id}/balance-evolution?from={$from}&to={$to}");

    $response->assertSuccessful();

    $data = $response->json('data');
    $projectedPoints = collect($data)->where('projected', true);

    expect($projectedPoints)->not->toBeEmpty();

    // All projected points should have a value
    $projectedPoints->each(function ($point) {
        expect($point['value'])->toBeInt();
        expect($point['projected'])->toBeTrue();
    });
});

it('does not return projected data for non-loan accounts', function () {
    actingAs($this->user);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'type' => AccountType::Checking,
    ]);

    AccountBalance::create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth()->subMonth()->toDateString(),
        'balance' => 500000,
    ]);

    $from = now()->subMonths(6)->toDateString();
    $to = now()->toDateString();

    $response = $this->getJson("/api/dashboard/account/{$account->id}/balance-evolution?from={$from}&to={$to}");

    $response->assertSuccessful();

    $data = $response->json('data');
    $projectedPoints = collect($data)->where('projected', true);

    expect($projectedPoints)->toBeEmpty();
});

// -------------------------------------------------------------------
// Model relationships
// -------------------------------------------------------------------

it('has a one-to-one relationship between account and loan detail', function () {
    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $detail = LoanDetail::factory()->create([
        'account_id' => $account->id,
        'annual_interest_rate' => 4.5,
    ]);

    expect($account->fresh()->loanDetail)->not->toBeNull();
    expect($account->fresh()->loanDetail->id)->toBe($detail->id);
    expect($detail->fresh()->account->id)->toBe($account->id);
});

it('preserves loan detail when account is soft deleted', function () {
    actingAs($this->user);

    $account = Account::factory()->loan()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
    ]);

    $detail = LoanDetail::factory()->create([
        'account_id' => $account->id,
    ]);

    $this->delete(route('accounts.destroy', $account));

    expect(Account::find($account->id))->toBeNull();
    expect(Account::withTrashed()->find($account->id))->not->toBeNull();

    assertDatabaseHas('loan_details', ['id' => $detail->id]);
});
