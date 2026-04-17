<?php

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Listeners\AssignTransactionToBudget;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('handle re-runs assignment when TransactionCreated fires', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
    ]);
    BudgetPeriod::factory()->create([
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

    // Reset any rows created by the model-dispatched event during factory create.
    BudgetTransaction::query()->delete();

    app(AssignTransactionToBudget::class)->handle(new TransactionCreated($transaction));

    expect(BudgetTransaction::query()->count())->toBe(1);
});

test('handle skips when TransactionUpdated has no budget-relevant changes', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
    ]);
    BudgetPeriod::factory()->create([
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

    // Only change a non-relevant attribute.
    $transaction->update(['description' => 'updated description']);

    $spy = Mockery::spy(BudgetTransactionService::class);
    $listener = new AssignTransactionToBudget($spy);

    $listener->handle(new TransactionUpdated($transaction));

    $spy->shouldNotHaveReceived('assignTransaction');
});

test('handle runs when TransactionUpdated changes category', function () {
    $oldCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $budget = Budget::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $newCategory->id,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(30),
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $oldCategory->id,
        'transaction_date' => now()->subDays(2),
        'amount' => -1000,
    ]);

    BudgetTransaction::query()->delete();

    $transaction->update(['category_id' => $newCategory->id]);

    app(AssignTransactionToBudget::class)->handle(new TransactionUpdated($transaction));

    expect(BudgetTransaction::query()->count())->toBe(1);
});

test('listener uniqueId returns the transaction id', function () {
    $transaction = Transaction::factory()->create(['user_id' => $this->user->id]);

    $listener = app(AssignTransactionToBudget::class);

    expect($listener->uniqueId(new TransactionCreated($transaction)))->toBe($transaction->id);
});

test('duplicate listener dispatches for the same transaction only queue once', function () {
    Queue::fake();

    $transaction = Transaction::factory()->create(['user_id' => $this->user->id]);

    TransactionCreated::dispatch($transaction);
    TransactionUpdated::dispatch($transaction);

    Queue::assertPushed(
        CallQueuedListener::class,
        fn ($job) => $job->class === AssignTransactionToBudget::class,
    );

    $pushed = collect(Queue::pushed(CallQueuedListener::class))
        ->filter(fn ($job) => $job->class === AssignTransactionToBudget::class);

    expect($pushed->count())->toBe(1);
});
