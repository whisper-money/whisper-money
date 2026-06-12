<?php

namespace App\Services\Ai;

use App\Ai\Agents\RuleSuggestionAgent;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use Laravel\Ai\Enums\Lab;

class LaravelAiRuleSuggestionGenerator implements RuleSuggestionGenerator
{
    public function generate(array $groups, array $categoryOptions): array
    {
        if ($groups === []) {
            return [];
        }

        $payload = json_encode([
            'transaction_groups' => $groups,
            'categories' => $categoryOptions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = (new RuleSuggestionAgent)->prompt(
            $payload,
            provider: Lab::Gemini,
            model: (string) config('ai_suggestions.model'),
        );

        $suggestions = $response['suggestions'] ?? [];

        return is_array($suggestions) ? array_values($suggestions) : [];
    }
}
