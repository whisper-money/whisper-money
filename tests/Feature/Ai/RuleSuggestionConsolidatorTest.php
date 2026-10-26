<?php

use App\Services\Ai\RuleSuggestionConsolidator;

beforeEach(function () {
    $this->consolidator = new RuleSuggestionConsolidator;

    $this->make = fn (array $overrides = []): array => array_merge([
        'match_field' => 'description',
        'match_operator' => 'contains',
        'match_token' => 'endesa',
        'proposed_category_id' => 'cat-1',
        'new_category_name' => null,
        'new_category_direction' => null,
    ], $overrides);
});

it('drops a narrower token already covered by a broader one in the same category', function () {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_token' => 'endesa']),
        ($this->make)(['match_token' => 'endesa energia']),
    ]);

    expect($result)->toHaveCount(1)
        ->and($result[0]['match_token'])->toBe('endesa');
});

it('keeps overlapping tokens that differ in category, match field or operator', function (array $broader, array $narrower) {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_token' => 'endesa', ...$broader]),
        ($this->make)(['match_token' => 'endesa energia', ...$narrower]),
    ]);

    expect($result)->toHaveCount(2);
})->with([
    'different categories' => [['proposed_category_id' => 'cat-1'], ['proposed_category_id' => 'cat-2']],
    'different match fields' => [['match_field' => 'description'], ['match_field' => 'creditor_name']],
    // Only contains tokens are pruned, never equals.
    'an equals token' => [['match_operator' => 'contains'], ['match_operator' => 'equals']],
]);

it('groups new-category proposals by name and direction when pruning', function () {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_token' => 'netflix', 'proposed_category_id' => null, 'new_category_name' => 'Streaming', 'new_category_direction' => 'outflow']),
        ($this->make)(['match_token' => 'netflix hd', 'proposed_category_id' => null, 'new_category_name' => 'Streaming', 'new_category_direction' => 'outflow']),
    ]);

    expect($result)->toHaveCount(1)
        ->and($result[0]['match_token'])->toBe('netflix');
});

it('collapses a substring chain down to the broadest token', function () {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_token' => 'endesa energia']),
        ($this->make)(['match_token' => 'endesa']),
        ($this->make)(['match_token' => 'endesa energia solar']),
    ]);

    expect($result)->toHaveCount(1)
        ->and($result[0]['match_token'])->toBe('endesa');
});
