<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
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

/**
 * A budget tracking one expense category over the current month, plus a shared
 * account holding a single expense in that category. The transaction is not
 * assigned yet, so each test decides which assignment path writes the snapshot.
 *
 * @return array{account: Account, period: BudgetPeriod, transaction: Transaction}
 */
function sharedAccountExpenseAndBudget(User $user, int $percentage, int $amount = -80000): array
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
        'ownership_percentage' => $percentage,
    ]);

    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 100000,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => $amount,
        'currency_code' => 'USD',
        'transaction_date' => now(),
    ]);

    return ['account' => $account, 'period' => $period, 'transaction' => $transaction];
}

test('a budget counts only the owner share of a transaction on a shared account', function () {
    ['period' => $period, 'transaction' => $transaction] = sharedAccountExpenseAndBudget($this->user, 50);

    app(BudgetTransactionService::class)->assignTransaction($transaction);

    expect($period->spentAmount())->toBe(40000)
        ->and($period->remainingAmount())->toBe(60000);
});

test('a fully owned account still counts in full towards a budget', function () {
    ['period' => $period, 'transaction' => $transaction] = sharedAccountExpenseAndBudget($this->user, 100);

    app(BudgetTransactionService::class)->assignTransaction($transaction);

    expect($period->spentAmount())->toBe(80000);
});

test('the historical backfill counts only the owner share of a shared account', function () {
    ['period' => $period] = sharedAccountExpenseAndBudget($this->user, 50);

    app(BudgetTransactionService::class)->assignHistoricalTransactionsToPeriod($period);

    expect($period->spentAmount())->toBe(40000);
});

test('changing an account share re-weighs the budget amounts already recorded', function () {
    ['account' => $account, 'period' => $period, 'transaction' => $transaction] = sharedAccountExpenseAndBudget($this->user, 100);

    app(BudgetTransactionService::class)->assignTransaction($transaction);
    expect($period->spentAmount())->toBe(80000);

    $this->patch(route('accounts.update', $account), [
        'name' => $account->name,
        'type' => AccountType::Checking->value,
        'currency_code' => 'USD',
        'ownership_percentage' => 50,
    ])->assertRedirect();

    expect($period->spentAmount())->toBe(40000);
});

/**
 * Budget amounts are written in PHP on assignment and rewritten in SQL when the
 * share changes. An amount that does not divide evenly is the only thing that
 * catches the two rounding rules drifting apart.
 */
test('the PHP and SQL budget paths round an uneven share identically', function () {
    ['account' => $account, 'period' => $period, 'transaction' => $transaction] = sharedAccountExpenseAndBudget($this->user, 33, amount: -3333);

    app(BudgetTransactionService::class)->assignTransaction($transaction);
    expect($period->spentAmount())->toBe(1100);

    // Round-trip through 100% and back so the SQL path recomputes the same 33%.
    $account->update(['ownership_percentage' => 100]);
    expect($period->spentAmount())->toBe(3333);

    $account->update(['ownership_percentage' => 33]);
    expect($period->spentAmount())->toBe(1100);
});

test('a re-weigh lets a budget notify again on the next crossing', function () {
    ['account' => $account, 'period' => $period, 'transaction' => $transaction] = sharedAccountExpenseAndBudget($this->user, 100, amount: -120000);

    app(BudgetTransactionService::class)->assignTransaction($transaction);
    $period->update(['over_limit_notified' => true, 'close_to_limit_notified' => true]);

    $account->update(['ownership_percentage' => 50]);

    expect($period->fresh()->over_limit_notified)->toBeFalse()
        ->and($period->fresh()->close_to_limit_notified)->toBeFalse();
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
