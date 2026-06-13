<?php

namespace App\Services\Ai\Contracts;

interface RuleSuggestionGenerator
{
    /**
     * Ask the model to map pre-aggregated transaction groups to categorization
     * rules. Returns the raw, unvalidated suggestions (guards run separately).
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $categoryOptions
     * @return list<array<string, mixed>>
     */
    public function generate(array $groups, array $categoryOptions): array;
}
