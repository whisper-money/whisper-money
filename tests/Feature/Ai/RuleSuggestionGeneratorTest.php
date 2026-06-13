<?php

use App\Ai\Agents\RuleSuggestionAgent;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;

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

it('splits groups into batches and merges their suggestions', function () {
    config()->set('ai_suggestions.group_batch_size', 1);

    RuleSuggestionAgent::fake([
        ['suggestions' => [genSuggestion('alpha')]],
        ['suggestions' => [genSuggestion('beta')]],
    ]);

    $generator = new LaravelAiRuleSuggestionGenerator;

    $suggestions = $generator->generate(
        groups: [benchGroup('alpha'), benchGroup('beta')],
        categoryOptions: [['id' => 'cat-1', 'name' => 'X', 'path' => 'X', 'type' => 'expense', 'direction' => 'outflow', 'is_leaf' => true]],
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
        categoryOptions: [['id' => 'cat-1', 'name' => 'X', 'path' => 'X', 'type' => 'expense', 'direction' => 'outflow', 'is_leaf' => true]],
    );

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['match_token'])->toBe('okkey');
});

it('rethrows when every batch fails', function () {
    config()->set('ai_suggestions.group_batch_size', 1);

    RuleSuggestionAgent::fake(function () {
        throw new RuntimeException('all batches failed');
    });

    $generator = new LaravelAiRuleSuggestionGenerator;

    expect(fn () => $generator->generate(
        groups: [benchGroup('alpha'), benchGroup('beta')],
        categoryOptions: [['id' => 'cat-1', 'name' => 'X', 'path' => 'X', 'type' => 'expense', 'direction' => 'outflow', 'is_leaf' => true]],
    ))->toThrow(RuntimeException::class);
});
