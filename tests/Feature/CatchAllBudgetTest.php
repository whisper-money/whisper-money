<?php

use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;

beforeEach(function () {
    $this->service = app(BudgetTransactionService::class);
    $this->user = User::factory()->create();
});

function catchAllPeriod(User $user): BudgetPeriod
{
    $budget = Budget::factory()->catchAll()->create(['user_id' => $user->id]);

    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);
}

test('catch-all budget absorbs an expense not tracked by any other budget', function () {
    $period = catchAllPeriod($this->user);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now(),
        'amount' => -1000,
    ]);

    $this->service->assignTransaction($transaction);

    $budgetTransaction = BudgetTransaction::where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->first();

    expect($budgetTransaction)->not->toBeNull();
    expect($budgetTransaction->amount)->toBe(1000);
});

test('catch-all budget ignores an expense already tracked by another budget', function () {
    $catchAll = catchAllPeriod($this->user);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    $tracked = Budget::factory()->forCategories($category)->create(['user_id' => $this->user->id]);
    $trackedPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now(),
        'amount' => -1000,
    ]);

    $this->service->assignTransaction($transaction);

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $trackedPeriod->id)->exists())->toBeTrue();
    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $catchAll->id)->exists())->toBeFalse();
});

test('catch-all budget ignores income transactions', function () {
    $period = catchAllPeriod($this->user);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now(),
        'amount' => 5000,
    ]);

    $this->service->assignTransaction($transaction);

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $period->id)->exists())->toBeFalse();
});

test('catch-all budget excludes a child whose parent category is tracked', function () {
    $catchAll = catchAllPeriod($this->user);

    $parent = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $child = Category::factory()->childOf($parent)->create();

    $tracked = Budget::factory()->forCategories($parent)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $child->id,
        'transaction_date' => now(),
        'amount' => -1000,
    ]);

    $this->service->assignTransaction($transaction);

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $catchAll->id)->exists())->toBeFalse();
});

test('historical assignment backfills only unclaimed expenses into a catch-all budget', function () {
    $loose = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $claimed = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $income = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]);

    // Transactions are created before any budget exists so they are not
    // auto-assigned on creation — the historical backfill does the work.
    // 2 unclaimed expenses (absorbed), 1 claimed expense + 1 income (ignored).
    Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $loose->id, 'transaction_date' => now()->subDay(), 'amount' => -1000]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $loose->id, 'transaction_date' => now()->subDays(2), 'amount' => -2000]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $claimed->id, 'transaction_date' => now()->subDay(), 'amount' => -3000]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $income->id, 'transaction_date' => now()->subDay(), 'amount' => 4000]);

    $tracked = Budget::factory()->forCategories($claimed)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $period = catchAllPeriod($this->user);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(2);
    expect(BudgetTransaction::where('budget_period_id', $period->id)->count())->toBe(2);
});
