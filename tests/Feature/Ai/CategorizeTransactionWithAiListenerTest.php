<?php

use App\Enums\CategoryType;
use App\Events\TransactionCreated;
use App\Listeners\CategorizeTransactionWithAi;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use App\Services\TransactionSplitter;
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

it('queues the AI for a split part left uncategorized, never for one given a category in the split', function () {
    $user = aiEligibleUser();
    $category = Category::factory()->for($user)->create();
    $original = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -10000,
    ]);

    Queue::fake();

    app(TransactionSplitter::class)->split($original, [
        ['amount' => -6000, 'category_id' => $category->id],
        ['amount' => -4000],
    ]);

    expect(aiCategorizationJobsQueued())->toBe(1);
});

it('does not categorize a transaction that gained splits while the queued job waited', function () {
    $user = aiEligibleUser();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -10000,
    ]);
    $food = Category::factory()->create(['user_id' => $user->id, 'space_id' => $account->space_id, 'type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $user->id, 'space_id' => $account->space_id, 'type' => CategoryType::Expense]);
    fileUnderLegacyLines($transaction, [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    $gate = Mockery::mock(AiCategorizationGate::class);
    $gate->shouldNotReceive('allows');
    $categorizer = Mockery::mock(AiCategorizer::class);
    $categorizer->shouldNotReceive('run');

    (new CategorizeTransactionWithAi($gate, $categorizer))
        ->handle(new TransactionCreated($transaction));

    expect($transaction->refresh()->category_id)->toBeNull();
});
