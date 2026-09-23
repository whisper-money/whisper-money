<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Jobs\RetryTransientAiCategorizationJob;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizer;
use App\Services\Ai\CategorizeTransactions;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function leafIndex(CategoryCatalog $catalog, string $categoryId): int
{
    $index = 0;

    while (($id = $catalog->categoryIdForIndex($index)) !== null) {
        if ($id === $categoryId) {
            return $index;
        }
        $index++;
    }

    throw new RuntimeException("category {$categoryId} is not a leaf in the catalog");
}

function groceries(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function uncategorized(User $user): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'category_source' => null,
        'amount' => -4300,
        'creditor_name' => 'mercadona',
        'description' => 'mercadona compra',
    ]);
}

it('auto-applies the category when confidence clears the label bar', function () {
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->category_source)->toBe(CategorySource::Ai)
        ->and($transaction->ai_confidence)->toEqual(0.95)
        ->and($transaction->ai_suggested_category_id)->toBe($category->id)
        ->and($transaction->ai_suggested_category_at)->not->toBeNull()
        ->and($transaction->ai_model)->toBe((string) config('ai_categorization.model'))
        ->and($outcomes)->toHaveCount(1)
        ->and($outcomes[0]->applied)->toBeTrue()
        ->and($outcomes[0]->merchantUnambiguous)->toBeTrue();
});

it('leaves the transaction blank but records the suggestion when confidence is below the label bar', function () {
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 0.5,
            'merchant_unambiguous' => false,
        ]]],
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($transaction->category_id)->toBeNull()
        ->and($transaction->category_source)->toBeNull()
        ->and($transaction->ai_suggested_category_id)->toBe($category->id)
        ->and($transaction->ai_confidence)->toEqual(0.5)
        ->and($transaction->ai_suggested_category_at)->not->toBeNull()
        ->and($transaction->ai_model)->toBe((string) config('ai_categorization.model'))
        ->and($outcomes)->toHaveCount(1)
        ->and($outcomes[0]->applied)->toBeFalse();
});

it('returns nothing when the user has no leaf categories', function () {
    $user = User::factory()->create();
    $transaction = uncategorized($user);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    expect($outcomes)->toBe([]);
});

it('drops the chunk, skips reporting and schedules a retry on a transient provider failure', function (Closure $makeFailure) {
    $user = User::factory()->create();
    groceries($user);
    $transaction = uncategorized($user);

    Exceptions::fake();
    Queue::fake();

    TransactionCategorizationAgent::fake(fn () => throw $makeFailure());

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($outcomes)->toBe([])
        ->and($transaction->category_id)->toBeNull();

    Exceptions::assertNothingReported();
    Queue::assertPushed(
        RetryTransientAiCategorizationJob::class,
        fn (RetryTransientAiCategorizationJob $job): bool => $job->user->is($user),
    );
})->with('transient provider failures');

it('reports unexpected failures and does not schedule a retry so real bugs are not swallowed', function () {
    $user = User::factory()->create();
    groceries($user);
    $transaction = uncategorized($user);

    Exceptions::fake();
    Queue::fake();

    TransactionCategorizationAgent::fake(fn () => throw new RuntimeException('malformed response'));

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    expect($outcomes)->toBe([]);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'malformed response');
    Queue::assertNotPushed(RetryTransientAiCategorizationJob::class);
});

it('routes prompts through the configured provider so local Ollama can be used', function () {
    config()->set('ai_categorization.provider', 'ollama');
    config()->set('ai_categorization.model', 'gemma3:12b');

    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    TransactionCategorizationAgent::assertPrompted(
        fn ($prompt): bool => (string) $prompt->provider === 'ollama'
            && $prompt->model === 'gemma3:12b',
    );
});

it('defaults the categorization provider to gemini for backward compatibility', function () {
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    TransactionCategorizationAgent::assertPrompted(
        fn ($prompt): bool => (string) $prompt->provider === 'gemini',
    );
});

it('skips results whose category index does not resolve', function () {
    $user = User::factory()->create();
    groceries($user);
    $transaction = uncategorized($user);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => 999,
            'confidence' => 0.99,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($outcomes)->toBe([])
        ->and($transaction->category_id)->toBeNull();
});

it('scores an out-of-scale confidence as zero rather than as certainty', function () {
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 200,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    // Zero, not 1.0: at 1.0 the answer would clear both the label bar and the
    // higher rule bar, so one malformed response would auto-apply the category
    // and teach a permanent merchant rule off it.
    expect($transaction->ai_confidence)->toEqual(0.0)
        ->and($transaction->category_id)->toBeNull()
        ->and($transaction->category_source)->toBeNull()
        ->and($transaction->ai_suggested_category_id)->toBe($category->id)
        ->and($outcomes[0]->confidence)->toEqual(0.0)
        ->and($outcomes[0]->applied)->toBeFalse();
});

it('scores a negative confidence as zero and leaves the transaction uncategorized', function () {
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => -5,
            'merchant_unambiguous' => false,
        ]]],
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($transaction->ai_confidence)->toEqual(0.0)
        ->and($transaction->category_id)->toBeNull()
        ->and($outcomes[0]->confidence)->toEqual(0.0)
        ->and($outcomes[0]->applied)->toBeFalse();
});

it('logs the raw value when the confidence is out of range', function () {
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 200,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    Log::spy();

    app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'AI categorization returned an out-of-range confidence'
            && $context['transaction_id'] === $transaction->id
            && $context['confidence'] === 200.0);
});

it('learns no rule from an out-of-scale confidence even when the merchant is unambiguous', function () {
    // Regression for the reviewed concern: clamping 200 up to 1.0 would clear
    // both the label bar and the higher rule bar, so one malformed response
    // would auto-apply the category AND teach a permanent merchant rule off
    // it. Scoring zero must keep AiRuleLearner::learn() below its confidence
    // bar too. Goes through AiCategorizer::run() (not just forTransactions())
    // because that is what actually feeds outcomes into the rule learner.
    $user = User::factory()->create();
    $category = groceries($user);
    $transaction = uncategorized($user);

    $index = leafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 200,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    app(AiCategorizer::class)->run($user, collect([$transaction]));

    $transaction->refresh();

    expect(AutomationRule::query()->count())->toBe(0)
        ->and($transaction->categorized_by_rule_id)->toBeNull()
        ->and($transaction->category_id)->toBeNull()
        ->and($transaction->ai_confidence)->toEqual(0.0);
});
