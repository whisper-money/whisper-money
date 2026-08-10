<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $this->actingAs($this->user);
});

/**
 * A checking account owned at the given percentage, with one income and one
 * expense transaction dated today.
 */
function sharedAccountWithTransactions(User $user, int $percentage, bool $appliesToBalance = false): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
        'ownership_percentage' => $percentage,
        'ownership_applies_to_balance' => $appliesToBalance,
    ]);

    $income = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Income,
    ]);
    $expense = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $income->id,
        'amount' => 200000,
        'currency_code' => 'USD',
        'transaction_date' => now(),
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $expense->id,
        'amount' => -80000,
        'currency_code' => 'USD',
        'transaction_date' => now(),
    ]);

    return $account;
}

test('accounts are fully owned by default', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    expect($account->fresh()->ownership_percentage)->toBe(100)
        ->and($account->fresh()->ownership_applies_to_balance)->toBeFalse();
});

test('the cashflow summary endpoint counts only the owner share of a shared account', function () {
    sharedAccountWithTransactions($this->user, 50);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('current.income'))->toBe(100000)
        ->and($response->json('current.expense'))->toBe(40000)
        ->and($response->json('current.net'))->toBe(60000);
});

test('the cashflow breakdown endpoint counts only the owner share of a shared account', function () {
    sharedAccountWithTransactions($this->user, 50);

    $response = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
        'type' => 'expense',
    ]));

    $response->assertOk();
    expect($response->json('total'))->toBe(40000);
});

test('the dashboard cashflow endpoint counts only the owner share of a shared account', function () {
    sharedAccountWithTransactions($this->user, 50);

    $response = $this->getJson('/api/dashboard/cash-flow?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('current.income'))->toBe(100000)
        ->and($response->json('current.expense'))->toBe(40000);
});

test('the dashboard monthly spending endpoint counts only the owner share of a shared account', function () {
    sharedAccountWithTransactions($this->user, 50);

    $response = $this->getJson('/api/dashboard/monthly-spending?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('current'))->toBe(40000);
});

test('the dashboard top categories endpoint counts only the owner share of a shared account', function () {
    sharedAccountWithTransactions($this->user, 50);

    $response = $this->getJson('/api/dashboard/top-categories?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('0.amount'))->toBe(40000);
});

test('the dashboard page cashflow summary counts only the owner share of a shared account', function () {
    sharedAccountWithTransactions($this->user, 50);

    $response = $this->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'cashflowSummary',
    ]);

    $response->assertOk();
    expect($response->json('props.cashflowSummary.current.income'))->toBe(100000)
        ->and($response->json('props.cashflowSummary.current.expense'))->toBe(40000);
});

/**
 * The share is computed twice — in PHP for the row-by-row cashflow paths and in
 * SQL for the dashboard aggregates. An amount that does not divide evenly is the
 * only thing that catches the two rounding rules drifting apart.
 */
test('the PHP and SQL paths round an uneven share identically', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
        'ownership_percentage' => 33,
    ]);

    $expense = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    // 33% of 3333 is 1099.89, which has to land on the same side in both paths.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expense->id,
        'amount' => -3333,
        'currency_code' => 'USD',
        'transaction_date' => now(),
    ]);

    $query = http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]);

    $php = $this->getJson('/api/cashflow/summary?'.$query);
    $sql = $this->getJson('/api/dashboard/monthly-spending?'.$query);

    expect($php->json('current.expense'))->toBe(1100)
        ->and($sql->json('current'))->toBe(1100);
});

test('fully owned accounts are unaffected', function () {
    sharedAccountWithTransactions($this->user, 100);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('current.income'))->toBe(200000)
        ->and($response->json('current.expense'))->toBe(80000);
});

test('net worth keeps the full balance when the share is limited to transactions', function () {
    $account = sharedAccountWithTransactions($this->user, 50);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 300000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('current'))->toBe(300000);
});

test('net worth counts only the owner share when the account applies it to the balance', function () {
    $account = sharedAccountWithTransactions($this->user, 50, appliesToBalance: true);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 300000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('current'))->toBe(150000);
});

test('updating an account persists its ownership settings', function () {
    $bank = Bank::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    $this->patch(route('accounts.update', $account), [
        'name' => 'Joint account',
        'bank_id' => $bank->id,
        'type' => AccountType::Checking->value,
        'currency_code' => 'USD',
        'ownership_percentage' => 50,
        'ownership_applies_to_balance' => true,
    ])->assertRedirect();

    expect($account->fresh()->ownership_percentage)->toBe(50)
        ->and($account->fresh()->ownership_applies_to_balance)->toBeTrue();
});

test('the ownership percentage must stay between 1 and 100', function () {
    $bank = Bank::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    $this->patch(route('accounts.update', $account), [
        'name' => 'Joint account',
        'bank_id' => $bank->id,
        'type' => AccountType::Checking->value,
        'currency_code' => 'USD',
        'ownership_percentage' => 120,
    ])->assertSessionHasErrors('ownership_percentage');

    expect($account->fresh()->ownership_percentage)->toBe(100);
});
