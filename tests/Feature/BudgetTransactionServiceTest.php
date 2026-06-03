<?php

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = app(BudgetTransactionService::class);
    $this->user = User::factory()->create();
});

test('assignHistoricalTransactionsToPeriod returns correct count', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Create 5 historical transactions
    for ($i = 0; $i < 5; $i++) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'transaction_date' => now()->subDays($i + 1),
            'amount' => -1000,
        ]);
    }

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(5);
});

test('assignHistoricalTransactionsToPeriod handles empty results', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    // No transactions created
    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(0);
});

test('assignHistoricalTransactionsToPeriod processes large batches', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Create 1000 historical transactions
    $transactions = collect();
    for ($i = 0; $i < 1000; $i++) {
        $transactions->push([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'transaction_date' => now()->subDays(rand(1, 25)),
            'amount' => -rand(100, 10000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Insert in batches
    Transaction::insert($transactions->toArray());

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(1000);
})->skip('Run only when testing performance with large datasets');

test('assignHistoricalTransactionsToPeriod excludes transactions outside date range', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Create transactions outside period
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subMonths(6),
        'amount' => -1000,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->addMonths(6),
        'amount' => -1000,
    ]);

    // Create transaction inside period
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(1);
});

test('assignHistoricalTransactionsToPeriod works with category-based budgets', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $otherCategory = Category::factory()->create(['user_id' => $this->user->id]);

    // Create transaction with matching category
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);

    // Create transaction with non-matching category
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $otherCategory->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(1);
});

test('assignHistoricalTransactionsToPeriod works with label-based budgets', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $otherLabel = Label::factory()->create(['user_id' => $this->user->id]);

    // Create transaction with matching label
    $transaction1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);
    $transaction1->labels()->attach($label->id);

    // Create transaction with non-matching label
    $transaction2 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);
    $transaction2->labels()->attach($otherLabel->id);

    $budget = Budget::factory()->forLabels($label)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(1);
});

test('assignHistoricalTransactionsToPeriod works with transactions having multiple labels', function () {
    $targetLabel = Label::factory()->create(['user_id' => $this->user->id]);
    $otherLabel1 = Label::factory()->create(['user_id' => $this->user->id]);
    $otherLabel2 = Label::factory()->create(['user_id' => $this->user->id]);

    // Create transaction with multiple labels, including the target one
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);
    $transaction->labels()->attach([$targetLabel->id, $otherLabel1->id, $otherLabel2->id]);

    $budget = Budget::factory()->forLabels($targetLabel)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(1);
});

test('assignHistoricalTransactionsToPeriod stores negated transaction amount for expenses', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -5000, // Expense (negative)
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $this->service->assignHistoricalTransactionsToPeriod($period);

    $budgetTransaction = $period->budgetTransactions()->first();

    expect($budgetTransaction->amount)->toBe(5000); // -(-5000) = 5000, adds to spending
});

test('assignHistoricalTransactionsToPeriod stores refund as negative amount', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => 1000, // Refund (positive)
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $this->service->assignHistoricalTransactionsToPeriod($period);

    $budgetTransaction = $period->budgetTransactions()->first();

    expect($budgetTransaction->amount)->toBe(-1000); // -(+1000) = -1000, reduces spending
});

test('budget spending correctly reflects mix of expenses and refunds', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // $50 expense
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -5000,
    ]);

    // $10 refund
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(3),
        'amount' => 1000,
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
        'allocated_amount' => 10000,
    ]);

    $this->service->assignHistoricalTransactionsToPeriod($period);

    // Net spending should be $40 (5000 - 1000 = 4000)
    $totalSpent = (int) $period->budgetTransactions()->sum('amount');
    expect($totalSpent)->toBe(4000);
});

test('assignTransaction stores refund as negative budget transaction amount', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    // Create a refund transaction
    $refund = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(2),
        'amount' => 2000, // positive = refund
    ]);

    $this->service->assignTransaction($refund);

    $budgetTransaction = $period->budgetTransactions()->first();
    expect($budgetTransaction->amount)->toBe(-2000);
});

test('assignHistoricalTransactionsToPeriod only assigns to correct user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $category = Category::factory()->create(['user_id' => $user1->id]);

    // Create transaction for user2
    Transaction::factory()->create([
        'user_id' => $user2->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);

    // Create transaction for user1
    Transaction::factory()->create([
        'user_id' => $user1->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user1->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    // Should only assign user1's transaction
    expect($count)->toBe(1);
});

test('assignTransaction is idempotent when called twice (regression for PHP-LARAVEL-A)', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(2),
        'amount' => -1500,
    ]);

    $this->service->assignTransaction($transaction);
    $this->service->assignTransaction($transaction);

    expect($period->budgetTransactions()->count())->toBe(1)
        ->and((int) $period->budgetTransactions()->first()->amount)->toBe(1500);
});

test('assignTransaction retries deadlocks during reconciliation (regression for PHP-LARAVEL-D)', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(2),
        'amount' => -1500,
    ]);

    $databaseManager = DB::getFacadeRoot();

    DB::shouldReceive('transaction')
        ->once()
        ->withArgs(fn ($callback, $attempts) => $callback instanceof Closure && $attempts === 5)
        ->andReturnUsing(fn ($callback, $attempts) => $databaseManager->transaction($callback, $attempts));

    $this->service->assignTransaction($transaction);

    expect($period->budgetTransactions()->count())->toBe(1)
        ->and((int) $period->budgetTransactions()->first()->amount)->toBe(1500);
});

test('assignTransaction removes stale rows when category changes', function () {
    $oldCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    $oldBudget = Budget::factory()->forCategories($oldCategory)->create([
        'user_id' => $this->user->id,
    ]);
    $oldPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $oldBudget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $newBudget = Budget::factory()->forCategories($newCategory)->create([
        'user_id' => $this->user->id,
    ]);
    $newPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $newBudget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $oldCategory->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -2000,
    ]);

    $this->service->assignTransaction($transaction);
    expect($oldPeriod->budgetTransactions()->count())->toBe(1)
        ->and($newPeriod->budgetTransactions()->count())->toBe(0);

    $transaction->update(['category_id' => $newCategory->id]);
    $this->service->assignTransaction($transaction);

    expect($oldPeriod->budgetTransactions()->count())->toBe(0)
        ->and($newPeriod->budgetTransactions()->count())->toBe(1);
});

test('assignTransaction updates amount on existing row when transaction amount changes', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(2),
        'amount' => -1000,
    ]);

    $this->service->assignTransaction($transaction);
    $originalId = $period->budgetTransactions()->first()->id;

    $transaction->update(['amount' => -2500]);
    $this->service->assignTransaction($transaction);

    $budgetTransaction = $period->budgetTransactions()->first();
    expect($period->budgetTransactions()->count())->toBe(1)
        ->and($budgetTransaction->id)->toBe($originalId)
        ->and((int) $budgetTransaction->amount)->toBe(2500);
});

test('assignTransaction leaves table untouched when no budgets match', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(2),
        'amount' => -1000,
    ]);

    $this->service->assignTransaction($transaction);

    expect(BudgetTransaction::query()->count())->toBe(0);
});

test('assignTransaction survives pre-existing duplicate row (regression for PHP-LARAVEL-A/B race)', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -4000,
    ]);

    // Simulate the race: another worker already inserted a row with a stale
    // amount against the (transaction_id, budget_period_id) unique key after
    // the service's internal unassign step would have run.
    BudgetTransaction::where('transaction_id', $transaction->id)->delete();
    BudgetTransaction::create([
        'transaction_id' => $transaction->id,
        'budget_period_id' => $period->id,
        'amount' => 999, // stale amount from the other worker
    ]);

    // Re-run must not throw UniqueConstraintViolationException and must
    // converge the stored amount to the freshly computed value.
    $this->service->assignTransaction($transaction->fresh());

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and((int) BudgetTransaction::where('transaction_id', $transaction->id)->value('amount'))->toBe(4000);
});

test('assignHistoricalTransactionsToPeriod reruns converge existing rows without throwing', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -2500,
    ]);

    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    // First run creates the row.
    $this->service->assignHistoricalTransactionsToPeriod($period);

    // Tamper with the stored amount to prove the second run converges it
    // via updateOrCreate instead of hitting the unique constraint.
    BudgetTransaction::where('transaction_id', $transaction->id)->update(['amount' => 0]);

    // Second run must not throw on the existing (transaction_id, budget_period_id)
    // unique row and must refresh the amount back to the canonical value.
    $this->service->assignHistoricalTransactionsToPeriod($period);

    expect(BudgetTransaction::where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and((int) BudgetTransaction::where('transaction_id', $transaction->id)->value('amount'))->toBe(2500);
});

test('assignHistoricalTransactionsToPeriod matches transactions across multiple categories', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id]);
    $restaurants = Category::factory()->create(['user_id' => $this->user->id]);
    $unrelated = Category::factory()->create(['user_id' => $this->user->id]);

    foreach ([$food, $restaurants, $unrelated] as $category) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $category->id,
            'transaction_date' => now()->subDays(5),
            'amount' => -1000,
        ]);
    }

    $budget = Budget::factory()->forCategories([$food, $restaurants])->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(2);
});

test('assignHistoricalTransactionsToPeriod pools matches from categories and labels', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    // Matches via category.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);

    // Matches via label (different category).
    $labelled = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => Category::factory()->create(['user_id' => $this->user->id])->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1000,
    ]);
    $labelled->labels()->attach($label->id);

    $budget = Budget::factory()
        ->forCategories($category)
        ->forLabels($label)
        ->create(['user_id' => $this->user->id]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(2);
});

test('assignTransaction matches a budget tracking multiple categories', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id]);
    $restaurants = Category::factory()->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories([$food, $restaurants])->create([
        'user_id' => $this->user->id,
    ]);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $restaurants->id,
        'transaction_date' => now()->subDays(5),
        'amount' => -1500,
    ]);

    $this->service->assignTransaction($transaction);

    expect(BudgetTransaction::where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->exists())->toBeTrue();
});

test('a budget tracking a parent category includes child category transactions historically', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id]);
    $child = Category::factory()->childOf($parent)->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $parent->id,
        'transaction_date' => now()->subDay(),
        'amount' => -1000,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $child->id,
        'transaction_date' => now()->subDay(),
        'amount' => -2000,
    ]);

    $budget = Budget::factory()->forCategories($parent)->create(['user_id' => $this->user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $count = $this->service->assignHistoricalTransactionsToPeriod($period);

    expect($count)->toBe(2);
    expect((int) $period->budgetTransactions()->sum('amount'))->toBe(3000);
});

test('assigning a child category transaction matches a budget tracking the parent', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id]);
    $child = Category::factory()->childOf($parent)->create(['user_id' => $this->user->id]);

    $budget = Budget::factory()->forCategories($parent)->create(['user_id' => $this->user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $child->id,
        'transaction_date' => now(),
        'amount' => -1500,
    ]);

    $this->service->assignTransaction($transaction);

    expect(BudgetTransaction::where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->exists())->toBeTrue();
});
