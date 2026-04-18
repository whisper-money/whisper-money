<?php

use App\Events\TransactionCreated;
use App\Listeners\AssignTransactionToBudget;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function runQueuedAssignTransactionListener(): CallQueuedListener
{
    $queuedListener = collect(Queue::pushed(CallQueuedListener::class))
        ->first(fn (CallQueuedListener $job) => $job->class === AssignTransactionToBudget::class);

    expect($queuedListener)->toBeInstanceOf(CallQueuedListener::class)
        ->and($queuedListener->class)->toBe(AssignTransactionToBudget::class);

    $queuedListener = unserialize(serialize($queuedListener));

    $queuedListener->withFakeQueueInteractions();
    $queuedListener->handle(app());

    return $queuedListener;
}

test('queued listener re-runs assignment when TransactionCreated fires', function () {
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
    Queue::fake();

    TransactionCreated::dispatch($transaction);

    runQueuedAssignTransactionListener();

    expect(BudgetTransaction::query()->count())->toBe(1);
});

test('queued listener runs when TransactionUpdated changes category', function () {
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

    Queue::fake();
    $response = $this->actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $newCategory->id,
    ]);
    $response->assertSuccessful();

    runQueuedAssignTransactionListener();

    expect(BudgetTransaction::query()->count())->toBe(1);
});
