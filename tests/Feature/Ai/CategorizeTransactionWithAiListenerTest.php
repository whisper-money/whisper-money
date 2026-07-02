<?php

use App\Listeners\CategorizeTransactionWithAi;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

function aiEligibleUser(): User
{
    $user = User::factory()->onboarded()->create();
    $user->recordAiConsent();

    return $user;
}

function aiCategorizationJobsQueued(): int
{
    return Queue::pushed(CallQueuedListener::class)
        ->filter(fn (CallQueuedListener $job): bool => $job->class === CategorizeTransactionWithAi::class)
        ->count();
}

it('queues an AI categorization job for an eligible, uncategorized transaction', function () {
    $user = aiEligibleUser();

    Queue::fake();

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
    ]);

    expect(aiCategorizationJobsQueued())->toBe(1);
});

it('does not queue an AI categorization job when the user has no AI consent', function () {
    $user = User::factory()->onboarded()->create();

    Queue::fake();

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
    ]);

    expect(aiCategorizationJobsQueued())->toBe(0);
});

it('does not queue an AI categorization job when the transaction is already categorized', function () {
    $user = aiEligibleUser();
    $category = Category::factory()->for($user)->create();

    Queue::fake();

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    expect(aiCategorizationJobsQueued())->toBe(0);
});
