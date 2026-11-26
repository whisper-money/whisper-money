<?php

use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Features\Forecast;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\MatchTransactionToForecast;
use App\Listeners\UnassignTransactionFromBudget;
use App\Listeners\UnmatchTransactionFromForecast;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

function queuedListenerCount(string $listener): int
{
    return Queue::pushed(CallQueuedListener::class)
        ->filter(fn (CallQueuedListener $job): bool => $job->class === $listener)
        ->count();
}

it('registers each transaction listener exactly once', function (string $event, string $listener): void {
    $user = User::factory()->onboarded()->create();
    $transaction = Transaction::factory()->create(['user_id' => $user->id]);

    Queue::fake();

    event(new $event($transaction));

    expect(queuedListenerCount($listener))->toBe(1);
})->with([
    'created dispatches budget assignment once' => [TransactionCreated::class, AssignTransactionToBudget::class],
    'updated dispatches budget assignment once' => [TransactionUpdated::class, AssignTransactionToBudget::class],
    'deleted dispatches budget unassignment once' => [TransactionDeleted::class, UnassignTransactionFromBudget::class],
]);

it('registers each forecast matching listener exactly once', function (string $event): void {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(Forecast::class);
    $transaction = Transaction::factory()->create(['user_id' => $user->id]);
    ForecastItem::factory()->create(['user_id' => $user->id, 'account_id' => $transaction->account_id]);
    // An update event is only queued for an edit that changes what a match reads.
    $transaction->amount -= 1;
    $transaction->saveQuietly();

    Queue::fake();

    event(new $event($transaction));

    expect(queuedListenerCount(MatchTransactionToForecast::class))->toBe(1);
})->with([
    'created' => [TransactionCreated::class],
    'updated' => [TransactionUpdated::class],
]);

it('registers the forecast unmatching listener on delete, in line', function (): void {
    $match = ForecastMatch::factory()->create();

    expect(Event::hasListeners(TransactionDeleted::class))->toBeTrue();

    event(new TransactionDeleted($match->transaction));

    expect(ForecastMatch::query()->whereKey($match->id)->exists())->toBeFalse()
        ->and(collect(Event::getRawListeners()[TransactionDeleted::class] ?? [])->filter(fn ($listener): bool => str_contains((string) (is_array($listener) ? $listener[0] : $listener), UnmatchTransactionFromForecast::class)))->toHaveCount(1);
});
