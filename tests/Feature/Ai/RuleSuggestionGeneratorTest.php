<?php

use App\Ai\Agents\RuleSuggestionAgent;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;
use Illuminate\Support\Facades\Exceptions;

it('returns the structured suggestions produced by the model', function () {
    RuleSuggestionAgent::fake([
        [
            'suggestions' => [
                [
                    'group_key' => 'mercadona',
                    'match_field' => 'creditor_name',
                    'match_operator' => 'equals',
                    'match_token' => 'mercadona',
                    'category_id' => 'cat-1',
                    'confidence' => 0.96,
                ],
            ],
        ],
    ]);

    $generator = new LaravelAiRuleSuggestionGenerator;

    $suggestions = $generator->generate(
        groups: [['key' => 'mercadona', 'field' => 'creditor_name', 'count' => 14, 'avg_amount' => -42.1, 'direction' => 'outflow', 'samples' => ['mercadona compra']]],
        categoryOptions: [['id' => 'cat-1', 'name' => 'Groceries', 'path' => 'Food > Groceries', 'type' => 'expense', 'direction' => 'outflow', 'is_leaf' => true]],
    );

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['match_token'])->toBe('mercadona')
        ->and($suggestions[0]['category_id'])->toBe('cat-1');
});

it('skips the model entirely when there are no groups', function () {
    $generator = new LaravelAiRuleSuggestionGenerator;

    expect($generator->generate([], []))->toBe([]);
});

it('prompts the configured provider and model', function (array $config, string $provider, ?string $model) {
    config()->set($config);

    RuleSuggestionAgent::fake([['suggestions' => []]]);

    (new LaravelAiRuleSuggestionGenerator)->generate(
        groups: [benchGroup('alpha')],
        categoryOptions: benchCategoryOptions(),
    );

    $model ??= (string) config('ai_suggestions.model');

    RuleSuggestionAgent::assertPrompted(
        fn ($prompt): bool => (string) $prompt->provider === $provider
            && $prompt->model === $model,
    );
})->with([
    'defaulting to gemini and the configured model' => [[], 'gemini', null],
    'Ollama, when configured for local AI' => [['ai_suggestions.provider' => 'ollama', 'ai_suggestions.model' => 'gemma3:12b'], 'ollama', 'gemma3:12b'],
]);

function genSuggestion(string $key): array
{
    return [
        'group_key' => $key,
        'match_field' => 'creditor_name',
        'match_operator' => 'equals',
        'match_token' => $key,
        'category_id' => 'cat-1',
        'confidence' => 0.9,
    ];
}

function benchGroup(string $key): array
{
    return ['key' => $key, 'field' => 'creditor_name', 'count' => 3, 'avg_amount' => -10.0, 'direction' => 'outflow', 'samples' => [$key]];
}

/**
 * @return list<array<string, mixed>>
 */
function benchCategoryOptions(): array
{
    return [['id' => 'cat-1', 'name' => 'X', 'path' => 'X', 'type' => 'expense', 'direction' => 'outflow', 'is_leaf' => true]];
}

it('splits groups into batches and merges their suggestions', function () {
    config()->set('ai_suggestions.group_batch_size', 1);

    RuleSuggestionAgent::fake([
        ['suggestions' => [genSuggestion('alpha')]],
        ['suggestions' => [genSuggestion('beta')]],
    ]);

    $generator = new LaravelAiRuleSuggestionGenerator;

    $suggestions = $generator->generate(
        groups: [benchGroup('alpha'), benchGroup('beta')],
        categoryOptions: benchCategoryOptions(),
    );

    expect($suggestions)->toHaveCount(2)
        ->and(array_column($suggestions, 'match_token'))->toBe(['alpha', 'beta']);
});

it('keeps successful batches when one batch fails after retry', function () {
    config()->set('ai_suggestions.group_batch_size', 1);

    RuleSuggestionAgent::fake(function (string $prompt) {
        if (str_contains($prompt, 'boomtoken')) {
            throw new RuntimeException('batch failed');
        }

        return ['suggestions' => [genSuggestion('okkey')]];
    });

    $generator = new LaravelAiRuleSuggestionGenerator;

    $suggestions = $generator->generate(
        groups: [benchGroup('boomtoken'), benchGroup('goodkey')],
        categoryOptions: benchCategoryOptions(),
    );

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['match_token'])->toBe('okkey');
});

it('does not report an expected transient provider failure', function (Closure $makeFailure) {
    config()->set('ai_suggestions.group_batch_size', 1);

    Exceptions::fake();

    RuleSuggestionAgent::fake(function (string $prompt) use ($makeFailure) {
        if (str_contains($prompt, 'boomtoken')) {
            throw $makeFailure();
        }

        return ['suggestions' => [genSuggestion('okkey')]];
    });

    $generator = new LaravelAiRuleSuggestionGenerator;

    $suggestions = $generator->generate(
        groups: [benchGroup('boomtoken'), benchGroup('goodkey')],
        categoryOptions: benchCategoryOptions(),
    );

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['match_token'])->toBe('okkey');

    Exceptions::assertNothingReported();
})->with('transient provider failures');

it('reports an unexpected batch failure', function () {
    config()->set('ai_suggestions.group_batch_size', 1);

    Exceptions::fake();

    RuleSuggestionAgent::fake(function (string $prompt) {
        if (str_contains($prompt, 'boomtoken')) {
            throw new RuntimeException('batch failed');
        }

        return ['suggestions' => [genSuggestion('okkey')]];
    });

    $generator = new LaravelAiRuleSuggestionGenerator;

    $generator->generate(
        groups: [benchGroup('boomtoken'), benchGroup('goodkey')],
        categoryOptions: benchCategoryOptions(),
    );

    Exceptions::assertReported(RuntimeException::class);
});

it('rethrows when every batch fails', function () {
    config()->set('ai_suggestions.group_batch_size', 1);

    RuleSuggestionAgent::fake(function () {
        throw new RuntimeException('all batches failed');
    });

    $generator = new LaravelAiRuleSuggestionGenerator;

    expect(fn () => $generator->generate(
        groups: [benchGroup('alpha'), benchGroup('beta')],
        categoryOptions: benchCategoryOptions(),
    ))->toThrow(RuntimeException::class);
});
