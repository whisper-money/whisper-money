<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Jobs\RetryTransientAiCategorizationJob;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategorizeTransactions;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

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
    return Transaction::factory()->plaintext()->create([
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

it('never sends client-side encrypted transactions to the model', function () {
    $user = User::factory()->create();
    groceries($user);

    $encrypted = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'description_iv' => str_repeat('a', 16),
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$encrypted]));

    $encrypted->refresh();

    expect($outcomes)->toBe([])
        ->and($encrypted->category_id)->toBeNull();
});

it('drops the chunk, skips reporting and schedules a retry when the provider is transiently overloaded', function () {
    $user = User::factory()->create();
    groceries($user);
    $transaction = uncategorized($user);

    Exceptions::fake();
    Queue::fake();

    TransactionCategorizationAgent::fake(fn () => throw ProviderOverloadedException::forProvider('gemini'));

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($outcomes)->toBe([])
        ->and($transaction->category_id)->toBeNull();

    Exceptions::assertNothingReported();
    Queue::assertPushed(
        RetryTransientAiCategorizationJob::class,
        fn (RetryTransientAiCategorizationJob $job): bool => $job->user->is($user),
    );
});

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
