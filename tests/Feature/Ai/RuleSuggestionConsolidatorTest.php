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

it('keeps overlapping tokens that target different categories', function () {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_token' => 'endesa', 'proposed_category_id' => 'cat-1']),
        ($this->make)(['match_token' => 'endesa energia', 'proposed_category_id' => 'cat-2']),
    ]);

    expect($result)->toHaveCount(2);
});

it('does not prune across different match fields', function () {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_field' => 'description', 'match_token' => 'endesa']),
        ($this->make)(['match_field' => 'creditor_name', 'match_token' => 'endesa energia']),
    ]);

    expect($result)->toHaveCount(2);
});

it('only prunes contains tokens, never equals', function () {
    $result = $this->consolidator->consolidate([
        ($this->make)(['match_operator' => 'contains', 'match_token' => 'endesa']),
        ($this->make)(['match_operator' => 'equals', 'match_token' => 'endesa energia']),
    ]);

    expect($result)->toHaveCount(2);
});

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
