<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Features\JevCategorization;
use App\Jobs\RetryTransientAiCategorizationJob;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategorizeTransactions;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

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

/**
 * @return array<string, mixed>
 */
function jevAnswer(string $choice, float $confidence = 0.9, float $noul = 0.8): array
{
    return [
        'model' => 'jev-latest',
        'answers' => [
            'category' => ['type' => 'choice', 'choice' => $choice, 'probabilities' => [$choice => $confidence], 'confidence' => $confidence],
            'merchant_unambiguous' => ['type' => 'noul', 'noul' => $noul],
        ],
        'usage' => [],
    ];
}

describe('Jev backend', function () {
    beforeEach(function () {
        config()->set('services.typesafe.key', 'test-key');
        Http::preventStrayRequests();
        TransactionCategorizationAgent::fake()->preventStrayPrompts();

        $this->user = User::factory()->create();
        $this->category = groceries($this->user);
        $this->transaction = uncategorized($this->user);
        $this->index = leafIndex(CategoryCatalog::forUser($this->user), $this->category->id);

        Feature::for($this->user)->activate(JevCategorization::class);
    });

    it('sends one request per transaction with criteria limited to its direction', function () {
        $income = Category::factory()->for($this->user)->create([
            'type' => CategoryType::Income,
            'cashflow_direction' => CategoryCashflowDirection::Inflow,
        ]);
        $transfers = Category::factory()->for($this->user)->create([
            'type' => CategoryType::Transfer,
            'cashflow_direction' => CategoryCashflowDirection::Hidden,
        ]);
        $second = uncategorized($this->user);
        $catalog = CategoryCatalog::forUser($this->user);
        $index = fn (Category $category): string => (string) leafIndex($catalog, $category->id);

        Http::fake(['api.typesafe.ai/*' => Http::response(jevAnswer('none'))]);

        app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction, $second]));

        $expectedCriteria = [
            $index($this->category) => $this->category->name,
            $index($transfers) => $transfers->name,
            'none' => 'No category fits',
        ];

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'jev-latest'
            && $request['state'] === [
                'text' => 'mercadona compra',
                'amount' => -43.0,
                'direction' => 'outflow',
                'creditor_name' => 'mercadona',
                'debtor_name' => $this->transaction->debtor_name,
            ]
            && $request['questions']['category']['type'] === 'choice'
            && $request['questions']['category']['criteria'] == $expectedCriteria
            && ! array_key_exists($index($income), $request['questions']['category']['criteria'])
            && $request['questions']['merchant_unambiguous']['type'] === 'noul');
    });

    it('applies the chosen category and records jev as the model', function () {
        Http::fake(['api.typesafe.ai/*' => Http::response(jevAnswer((string) $this->index, confidence: 0.92, noul: 0.8))]);

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        $this->transaction->refresh();

        expect($outcomes)->toHaveCount(1)
            ->and($outcomes[0]->applied)->toBeTrue()
            ->and($outcomes[0]->merchantUnambiguous)->toBeTrue()
            ->and($this->transaction->category_id)->toBe($this->category->id)
            ->and($this->transaction->ai_confidence)->toEqual(0.92)
            ->and($this->transaction->ai_model)->toBe('jev-latest');
    });

    it('treats a merchant score below the threshold as ambiguous', function () {
        Http::fake(['api.typesafe.ai/*' => Http::response(jevAnswer((string) $this->index, noul: 0.4))]);

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        expect($outcomes[0]->merchantUnambiguous)->toBeFalse();
    });

    it('leaves the transaction untouched when jev picks none', function () {
        Http::fake(['api.typesafe.ai/*' => Http::response(jevAnswer('none', confidence: 0.99))]);

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        $this->transaction->refresh();

        expect($outcomes)->toBe([])
            ->and($this->transaction->category_id)->toBeNull()
            ->and($this->transaction->ai_suggested_category_id)->toBeNull();
    });

    it('keeps the answered transactions and schedules a retry on a transient failure', function (Closure $failure) {
        Exceptions::fake();
        Queue::fake();
        $second = uncategorized($this->user);

        Http::fakeSequence('api.typesafe.ai/*')
            ->push(jevAnswer((string) $this->index))
            ->pushResponse($failure());

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction, $second]));

        expect($outcomes)->toHaveCount(1)
            ->and($outcomes[0]->transaction->is($this->transaction))->toBeTrue()
            ->and($second->refresh()->category_id)->toBeNull();

        Exceptions::assertNothingReported();
        Queue::assertPushed(RetryTransientAiCategorizationJob::class);
    })->with([
        'rate limited' => fn () => fn () => Http::response([], 429),
        'overloaded' => fn () => fn () => Http::response([], 529),
        'unreachable' => fn () => fn () => Http::failedConnection(),
    ]);

    it('reports a rejected request without scheduling a retry', function () {
        Exceptions::fake();
        Queue::fake();

        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => 'invalid key'], 401)]);

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        expect($outcomes)->toBe([]);

        Exceptions::assertReported(fn (RequestException $e): bool => $e->response->status() === 401);
        Queue::assertNotPushed(RetryTransientAiCategorizationJob::class);
    });

    it('stays on gemini when the flag is off', function () {
        Feature::for($this->user)->deactivate(JevCategorization::class);
        TransactionCategorizationAgent::fake([['results' => []]]);

        app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        Http::assertNothingSent();
        TransactionCategorizationAgent::assertPrompted(fn (): bool => true);
    });

    it('falls back to gemini when the flag is on but no key is set', function () {
        config()->set('services.typesafe.key', null);
        TransactionCategorizationAgent::fake([['results' => [[
            'ref' => $this->transaction->id,
            'category_index' => $this->index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]]]);

        app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        Http::assertNothingSent();
        expect($this->transaction->refresh()->ai_model)->toBe((string) config('ai_categorization.model'));
    });
});
