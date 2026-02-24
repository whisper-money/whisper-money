<?php

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;

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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'label_id' => $label->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'label_id' => $targetLabel->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
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

    $budget = Budget::factory()->create([
        'user_id' => $user1->id,
        'category_id' => $category->id,
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
