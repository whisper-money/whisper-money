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
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Illuminate\Support\Sleep;

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
    $category = aiExpenseCategory($user);
    $transaction = uncategorized($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

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
    $category = aiExpenseCategory($user);
    $transaction = uncategorized($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

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
    aiExpenseCategory($user);
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
    aiExpenseCategory($user);
    $transaction = uncategorized($user);

    Exceptions::fake();
    Queue::fake();

    TransactionCategorizationAgent::fake(fn () => throw new RuntimeException('malformed response'));

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    expect($outcomes)->toBe([]);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'malformed response');
    Queue::assertNotPushed(RetryTransientAiCategorizationJob::class);
});

it('prompts the configured categorization provider and model', function (array $config, string $provider, ?string $model) {
    config()->set($config);

    $user = User::factory()->create();
    $category = aiExpenseCategory($user);
    $transaction = uncategorized($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]],
    ]);

    app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $model ??= (string) config('ai_categorization.model');

    TransactionCategorizationAgent::assertPrompted(
        fn ($prompt): bool => (string) $prompt->provider === $provider
            && $prompt->model === $model,
    );
})->with([
    'gemini by default, for backward compatibility' => [[], 'gemini', null],
    'local Ollama, when configured' => [['ai_categorization.provider' => 'ollama', 'ai_categorization.model' => 'gemma3:12b'], 'ollama', 'gemma3:12b'],
]);

it('skips results whose category index does not resolve', function () {
    $user = User::factory()->create();
    aiExpenseCategory($user);
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
        config()->set('services.typesafe.enabled', true);
        Http::preventStrayRequests();
        Sleep::fake();
        TransactionCategorizationAgent::fake()->preventStrayPrompts();

        $this->user = User::factory()->create();
        $this->category = aiExpenseCategory($this->user);
        $this->transaction = uncategorized($this->user);
        $this->index = catalogLeafIndex(CategoryCatalog::forUser($this->user), $this->category->id);

        config()->set('ai_categorization.jev_ratio', 1.0);
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
        $index = fn (Category $category): string => (string) catalogLeafIndex($catalog, $category->id);

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
            ->pushResponse($failure())
            ->pushResponse($failure())
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
        'server error' => fn () => fn () => Http::response([], 503),
        'unreachable' => fn () => fn () => Http::failedConnection(),
    ]);

    it('retries a rate-limited request in place before deferring it', function () {
        Queue::fake();

        Http::fakeSequence('api.typesafe.ai/*')
            ->push([], 429)
            ->push(jevAnswer((string) $this->index, confidence: 0.95));

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        expect($outcomes)->toHaveCount(1)
            ->and($this->transaction->refresh()->category_id)->toBe($this->category->id);

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Queue::assertNotPushed(RetryTransientAiCategorizationJob::class);
    });

    it('reports a rejected request without scheduling a retry', function () {
        Exceptions::fake();
        Queue::fake();

        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => 'invalid key'], 401)]);

        $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        expect($outcomes)->toBe([]);

        Exceptions::assertReported(fn (RequestException $e): bool => $e->response->status() === 401);
        Queue::assertNotPushed(RetryTransientAiCategorizationJob::class);
    });

    it('splits a batch between jev and gemini at random by the ratio', function () {
        config()->set('ai_categorization.jev_ratio', 0.5);
        Lottery::fix([true, false]);
        $second = uncategorized($this->user);

        Http::fake(['api.typesafe.ai/*' => Http::response(jevAnswer((string) $this->index, confidence: 0.95))]);
        TransactionCategorizationAgent::fake([['results' => [[
            'ref' => $second->id,
            'category_index' => $this->index,
            'confidence' => 0.95,
            'merchant_unambiguous' => true,
        ]]]]);

        app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction, $second]));

        Http::assertSentCount(1);
        TransactionCategorizationAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, $second->id)
            && ! str_contains((string) $prompt->prompt, $this->transaction->id));
        expect($this->transaction->refresh()->ai_model)->toBe('jev-latest')
            ->and($second->refresh()->ai_model)->toBe((string) config('ai_categorization.model'));
    });

    it('stays on gemini when the ratio is zero', function () {
        config()->set('ai_categorization.jev_ratio', 0.0);
        TransactionCategorizationAgent::fake([['results' => []]]);

        app(CategorizeTransactions::class)->forTransactions($this->user, collect([$this->transaction]));

        Http::assertNothingSent();
        TransactionCategorizationAgent::assertPrompted(fn (): bool => true);
    });

    it('falls back to gemini when the ratio is set but no key is', function () {
        config()->set('services.typesafe.enabled', false);
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

it('scores a confidence outside 0..1 as zero and leaves the transaction uncategorized', function (int $confidence, bool $merchantUnambiguous) {
    $user = User::factory()->create();
    $category = aiExpenseCategory($user);
    $transaction = uncategorized($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

    TransactionCategorizationAgent::fake([
        ['results' => [[
            'ref' => $transaction->id,
            'category_index' => $index,
            'confidence' => $confidence,
            'merchant_unambiguous' => $merchantUnambiguous,
        ]]],
    ]);

    $outcomes = app(CategorizeTransactions::class)->forTransactions($user, collect([$transaction]));

    $transaction->refresh();

    expect($transaction->ai_confidence)->toEqual(0.0)
        ->and($transaction->category_id)->toBeNull()
        ->and($transaction->category_source)->toBeNull()
        ->and($transaction->ai_suggested_category_id)->toBe($category->id)
        ->and($outcomes[0]->confidence)->toEqual(0.0)
        ->and($outcomes[0]->applied)->toBeFalse();
})->with([
    // Zero, not 1.0: at 1.0 the answer would clear both the label bar and the
    // higher rule bar, so one malformed response would auto-apply the category
    // and teach a permanent merchant rule off it.
    'out of scale, rather than as certainty' => [200, true],
    'negative' => [-5, false],
]);

it('logs the raw value when the confidence is out of range', function () {
    $user = User::factory()->create();
    $category = aiExpenseCategory($user);
    $transaction = uncategorized($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

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
    $category = aiExpenseCategory($user);
    $transaction = uncategorized($user);

    $index = catalogLeafIndex(CategoryCatalog::forUser($user), $category->id);

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
