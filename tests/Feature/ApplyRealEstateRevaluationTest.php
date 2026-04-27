<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\RealEstateDetail;
use App\Models\User;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
});

// -------------------------------------------------------------------
// Applying revaluation to accounts with positive percentage
// -------------------------------------------------------------------

it('applies positive annual revaluation monthly', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 6.00, // 6% annual compounded monthly
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 10000000, // 100,000.00
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    $newBalance = AccountBalance::query()
        ->where('account_id', $account->id)
        ->where('balance_date', now()->toDateString())
        ->first();

    expect($newBalance)->not->toBeNull();
    // 10,000,000 * (1 + 0.06)^(1/12) ≈ 10,048,676
    expect($newBalance->balance)->toBe(10048676);
});

// -------------------------------------------------------------------
// Applying revaluation with negative percentage (depreciation)
// -------------------------------------------------------------------

it('applies negative annual revaluation monthly', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => -12.00, // -12% annual compounded monthly
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 20000000, // 200,000.00
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    $newBalance = AccountBalance::query()
        ->where('account_id', $account->id)
        ->where('balance_date', now()->toDateString())
        ->first();

    expect($newBalance)->not->toBeNull();
    // 20,000,000 * (1 - 0.12)^(1/12) ≈ 19,788,075
    expect($newBalance->balance)->toBe(19788075);
});

// -------------------------------------------------------------------
// Skipping accounts without balance
// -------------------------------------------------------------------

it('skips accounts that have no existing balance', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 5.00,
    ]);

    // No balance created

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    expect(AccountBalance::where('account_id', $account->id)->count())->toBe(0);
});

// -------------------------------------------------------------------
// Skipping accounts with null revaluation percentage
// -------------------------------------------------------------------

it('skips accounts with null revaluation percentage', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => null,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 10000000,
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    // Should not create a new balance for today
    expect(
        AccountBalance::query()
            ->where('account_id', $account->id)
            ->where('balance_date', now()->toDateString())
            ->exists()
    )->toBeFalse();
});

// -------------------------------------------------------------------
// Skipping accounts with zero revaluation percentage
// -------------------------------------------------------------------

it('skips accounts with zero revaluation percentage', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 0,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 10000000,
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    expect(
        AccountBalance::query()
            ->where('account_id', $account->id)
            ->where('balance_date', now()->toDateString())
            ->exists()
    )->toBeFalse();
});

// -------------------------------------------------------------------
// Uses the latest balance as the basis for revaluation
// -------------------------------------------------------------------

it('uses the latest balance for revaluation calculation', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 12.00, // 12% annual compounded monthly
    ]);

    // Older balance
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonths(3)->toDateString(),
        'balance' => 5000000,
    ]);

    // More recent balance — this should be used
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 10000000,
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    $newBalance = AccountBalance::query()
        ->where('account_id', $account->id)
        ->where('balance_date', now()->toDateString())
        ->first();

    // Should use 10,000,000 not 5,000,000
    // 10,000,000 * (1 + 0.12)^(1/12) ≈ 10,094,888
    expect($newBalance->balance)->toBe(10094888);
});

// -------------------------------------------------------------------
// Processes multiple accounts
// -------------------------------------------------------------------

it('processes multiple real estate accounts', function () {
    $account1 = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);
    RealEstateDetail::factory()->create([
        'account_id' => $account1->id,
        'revaluation_percentage' => 12.00,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account1->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 10000000,
    ]);

    $user2 = User::factory()->onboarded()->create();
    $account2 = Account::factory()->realEstate()->create([
        'user_id' => $user2->id,
    ]);
    RealEstateDetail::factory()->create([
        'account_id' => $account2->id,
        'revaluation_percentage' => 6.00,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => now()->subMonth()->toDateString(),
        'balance' => 20000000,
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    $balance1 = AccountBalance::query()
        ->where('account_id', $account1->id)
        ->where('balance_date', now()->toDateString())
        ->first();

    $balance2 = AccountBalance::query()
        ->where('account_id', $account2->id)
        ->where('balance_date', now()->toDateString())
        ->first();

    expect($balance1->balance)->toBe(10094888); // 10M * (1.12)^(1/12)
    expect($balance2->balance)->toBe(20097351); // 20M * (1.06)^(1/12)
});

// -------------------------------------------------------------------
// Upserts balance for today if one already exists
// -------------------------------------------------------------------

it('updates existing balance for today instead of creating duplicate', function () {
    $account = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 12.00,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->toDateString(),
        'balance' => 10000000,
    ]);

    artisan('real-estate:apply-revaluation')->assertSuccessful();

    // Should have exactly one balance for today (upserted, not duplicated)
    expect(
        AccountBalance::query()
            ->where('account_id', $account->id)
            ->where('balance_date', now()->toDateString())
            ->count()
    )->toBe(1);

    $balance = AccountBalance::query()
        ->where('account_id', $account->id)
        ->where('balance_date', now()->toDateString())
        ->first();

    // 10,000,000 * (1 + 0.12)^(1/12) ≈ 10,094,888
    expect($balance->balance)->toBe(10094888);
});
