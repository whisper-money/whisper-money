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
