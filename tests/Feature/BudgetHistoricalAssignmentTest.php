<?php

use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseHas;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarded_at' => now()]);
});

test('budget creation dispatches the historical assignment job', function () {
    Queue::fake();

    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Test Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    Queue::assertPushed(AssignHistoricalTransactionsToBudget::class);
});

test('budget creation dispatches the historical assignment job for the current and previous periods', function () {
    Queue::fake();

    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Test Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    Queue::assertPushed(AssignHistoricalTransactionsToBudget::class, 2);

    $budget = $this->user->budgets()->first();

    expect($budget->periods()->count())->toBe(2);
});

test('historical transactions in the previous period are assigned for comparison', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $previousPeriodTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subMonthNoOverflow()->startOfMonth(),
        'amount' => -4000,
    ]);

    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Comparison Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    $this->artisan('queue:work --stop-when-empty');

    $budget = $this->user->budgets()->first();
    $previousPeriod = $budget->periods()->orderBy('start_date')->first();

    expect($previousPeriod->start_date->toDateString())->toBe(now()->subMonthNoOverflow()->startOfMonth()->toDateString());

    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $previousPeriodTransaction->id,
        'budget_period_id' => $previousPeriod->id,
    ]);
});

test('historical transactions matching by category are assigned', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Create historical transactions within the current month period
    $transaction1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->startOfMonth(),
        'amount' => -5000,
    ]);

    $transaction2 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->startOfMonth()->addDays(1),
        'amount' => -3000,
    ]);

    // Create budget - this should assign the historical transactions
    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Category Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    // Process the queued job
    $this->artisan('queue:work --once');

    // Verify assignments were created
    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $transaction1->id,
    ]);

    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $transaction2->id,
    ]);
});

test('historical transactions matching by label are assigned', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    // Create historical transaction with label within the current month period
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => now()->startOfMonth(),
        'amount' => -2500,
    ]);

    $transaction->labels()->attach($label->id);

    // Create budget with label
    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Label Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'label_ids' => [$label->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    // Process the queued job
    $this->artisan('queue:work --once');

    // Verify assignment was created
    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $transaction->id,
    ]);
});

test('transactions outside the period date range are not assigned', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Create transaction outside current period (way in the past)
    $oldTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subMonths(6),
        'amount' => -5000,
    ]);

    // Create transaction in current period
    $currentTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->startOfMonth(),
        'amount' => -3000,
    ]);

    // Create budget
    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Date Range Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    // Process the queued job
    $this->artisan('queue:work --once');

    // Verify only current transaction was assigned
    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $currentTransaction->id,
    ]);

    // Old transaction should NOT be assigned
    $this->assertDatabaseMissing('budget_transactions', [
        'transaction_id' => $oldTransaction->id,
    ]);
});

test('transactions on boundary dates are assigned', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Determine current period boundaries for a monthly budget starting on day 1
    $startDate = now()->startOfMonth();
    $endDate = now()->endOfMonth();

    // Create transactions on exact boundary dates
    $startTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => $startDate,
        'amount' => -1000,
    ]);

    $endTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => $endDate,
        'amount' => -2000,
    ]);

    // Create budget
    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Boundary Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    // Process the queued job
    $this->artisan('queue:work --once');

    // Both boundary transactions should be assigned
    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $startTransaction->id,
    ]);

    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $endTransaction->id,
    ]);
});

test('soft deleted transactions are not assigned', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Create and soft delete a transaction within the current month period
    $deletedTransaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->startOfMonth(),
        'amount' => -5000,
    ]);

    $deletedTransaction->delete();

    // Create budget
    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Soft Delete Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    // Process the queued job
    $this->artisan('queue:work --once');

    // Soft deleted transaction should NOT be assigned
    $this->assertDatabaseMissing('budget_transactions', [
        'transaction_id' => $deletedTransaction->id,
    ]);
});

test('duplicate assignments are prevented', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->startOfMonth(),
        'amount' => -5000,
    ]);

    // Create budget and process job
    $response = $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Duplicate Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();
    $this->artisan('queue:work --once');

    // Get the budget and period
    $budget = $this->user->budgets()->first();
    $period = $budget->getCurrentPeriod();

    // Count initial assignments
    $initialCount = BudgetTransaction::where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->count();

    expect($initialCount)->toBe(1);

    // Try to dispatch the job again manually
    AssignHistoricalTransactionsToBudget::dispatch($budget, $period);
    $this->artisan('queue:work --once');

    // Count should still be 1 (no duplicates)
    $finalCount = BudgetTransaction::where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->count();

    expect($finalCount)->toBe(1);
});

test('multiple budgets assign independently', function () {
    $category1 = Category::factory()->create(['user_id' => $this->user->id]);
    $category2 = Category::factory()->create(['user_id' => $this->user->id]);

    // Create transactions for each category within the current month period
    $transaction1 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category1->id,
        'transaction_date' => now()->startOfMonth(),
        'amount' => -3000,
    ]);

    $transaction2 = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category2->id,
        'transaction_date' => now()->startOfMonth()->addDays(1),
        'amount' => -2000,
    ]);

    // Create first budget
    $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Budget 1',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category1->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    // Create second budget
    $this->actingAs($this->user)->post('/budgets', [
        'name' => 'Budget 2',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category2->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    // Process both jobs
    $this->artisan('queue:work --stop-when-empty');

    // Verify each transaction is only assigned to its matching budget
    $budget1 = $this->user->budgets()->where('name', 'Budget 1')->first();
    $budget2 = $this->user->budgets()->where('name', 'Budget 2')->first();

    $period1 = $budget1->getCurrentPeriod();
    $period2 = $budget2->getCurrentPeriod();

    // Transaction 1 should only be in budget 1
    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $transaction1->id,
        'budget_period_id' => $period1->id,
    ]);

    $this->assertDatabaseMissing('budget_transactions', [
        'transaction_id' => $transaction1->id,
        'budget_period_id' => $period2->id,
    ]);

    // Transaction 2 should only be in budget 2
    assertDatabaseHas('budget_transactions', [
        'transaction_id' => $transaction2->id,
        'budget_period_id' => $period2->id,
    ]);

    $this->assertDatabaseMissing('budget_transactions', [
        'transaction_id' => $transaction2->id,
        'budget_period_id' => $period1->id,
    ]);
});
