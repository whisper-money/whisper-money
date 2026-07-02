<?php

use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\UnassignTransactionFromBudget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

function queuedListenerCount(string $listener): int
{
    return Queue::pushed(CallQueuedListener::class)
        ->filter(fn (CallQueuedListener $job): bool => $job->class === $listener)
        ->count();
}

it('registers each transaction listener exactly once', function (string $event, string $listener): void {
    $user = User::factory()->onboarded()->create();
    $transaction = Transaction::factory()->plaintext()->create(['user_id' => $user->id]);

    Queue::fake();

    event(new $event($transaction));

    expect(queuedListenerCount($listener))->toBe(1);
})->with([
    'created dispatches budget assignment once' => [TransactionCreated::class, AssignTransactionToBudget::class],
    'updated dispatches budget assignment once' => [TransactionUpdated::class, AssignTransactionToBudget::class],
    'deleted dispatches budget unassignment once' => [TransactionDeleted::class, UnassignTransactionFromBudget::class],
]);
