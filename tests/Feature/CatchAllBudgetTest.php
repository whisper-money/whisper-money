<?php

use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Support\Facades\Mail;

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

test('catch-all budget ignores an expense whose label is tracked by another budget', function () {
    $catchAll = catchAllPeriod($this->user);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $tracked = Budget::factory()->forLabels($label)->create(['user_id' => $this->user->id]);
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
    $transaction->labels()->attach($label);

    $this->service->assignTransaction($transaction->load('labels'));

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $trackedPeriod->id)->exists())->toBeTrue();
    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $catchAll->id)->exists())->toBeFalse();
});

test('catch-all budget still absorbs an expense carrying a label no budget tracks', function () {
    $catchAll = catchAllPeriod($this->user);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    // Another budget claims a different label, so "some label is claimed" must
    // not be enough to drop this expense.
    $other = Budget::factory()->forLabels(Label::factory()->create(['user_id' => $this->user->id]))->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $other->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now(),
        'amount' => -1000,
    ]);
    $transaction->labels()->attach($label);

    $this->service->assignTransaction($transaction->load('labels'));

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $catchAll->id)->exists())->toBeTrue();
});

test('catch-all budget keeps an expense whose label budget has no period covering it', function () {
    $catchAll = catchAllPeriod($this->user);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    // The label budget was created later and only covers future dates, so it
    // cannot take this expense — dropping it from the catch-all would leave it
    // out of every budget.
    $tracked = Budget::factory()->forLabels($label)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->addDays(10),
        'end_date' => now()->addDays(40),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now(),
        'amount' => -1000,
    ]);
    $transaction->labels()->attach($label);

    $this->service->assignTransaction($transaction->load('labels'));

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->where('budget_period_id', $catchAll->id)->exists())->toBeTrue();
});

test('historical assignment keeps an expense whose label budget has no period covering it', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $category->id, 'transaction_date' => now()->subDay(), 'amount' => -1000]);
    $transaction->labels()->attach($label);

    $tracked = Budget::factory()->forLabels($label)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->addDays(40),
        'end_date' => now()->addDays(70),
    ]);

    $period = catchAllPeriod($this->user);

    expect($this->service->assignHistoricalTransactionsToPeriod($period))->toBe(1);
    expect(BudgetTransaction::where('budget_period_id', $period->id)->where('transaction_id', $transaction->id)->exists())->toBeTrue();
});

test('historical assignment skips expenses whose label another budget tracks', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $labelled = Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $category->id, 'transaction_date' => now()->subDay(), 'amount' => -1000]);
    $labelled->labels()->attach($label);
    Transaction::factory()->create(['user_id' => $this->user->id, 'category_id' => $category->id, 'transaction_date' => now()->subDay(), 'amount' => -2000]);

    $tracked = Budget::factory()->forLabels($label)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $period = catchAllPeriod($this->user);

    expect($this->service->assignHistoricalTransactionsToPeriod($period))->toBe(1);
    expect(BudgetTransaction::where('budget_period_id', $period->id)->where('transaction_id', $labelled->id)->exists())->toBeFalse();
});

test('the reassign command moves a labeled transaction out of the catch-all budget', function () {
    Mail::fake();

    $catchAll = catchAllPeriod($this->user);
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now(),
        'amount' => -1000,
    ]);
    // The state the bug left behind: absorbed by the catch-all on creation, then
    // labelled — attaching a label fires no model event, so nothing reassigned it.
    expect(BudgetTransaction::where('budget_period_id', $catchAll->id)->exists())->toBeTrue();

    $transaction->labels()->attach($label);

    $tracked = Budget::factory()->forLabels($label)->create(['user_id' => $this->user->id]);
    $trackedPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $tracked->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $this->artisan('budgets:reassign-labeled', ['--dry-run' => true])->assertSuccessful();
    expect(BudgetTransaction::where('budget_period_id', $catchAll->id)->where('transaction_id', $transaction->id)->exists())->toBeTrue();

    $this->artisan('budgets:reassign-labeled')->assertSuccessful();

    expect(BudgetTransaction::where('budget_period_id', $catchAll->id)->where('transaction_id', $transaction->id)->exists())->toBeFalse();
    expect(BudgetTransaction::where('budget_period_id', $trackedPeriod->id)->where('transaction_id', $transaction->id)->exists())->toBeTrue();

    // A repair sweep must not email the user about thresholds crossed weeks ago.
    Mail::assertNothingSent();
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
