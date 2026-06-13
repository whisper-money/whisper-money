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

        $batchSize = max(1, (int) config('ai_suggestions.group_batch_size'));

        $suggestions = [];

        foreach (array_chunk($groups, $batchSize) as $batch) {
            foreach ($this->generateBatch($batch, $categoryOptions) as $suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Send one bounded batch of groups to the model. Large single payloads make
     * the model silently skip groups, so callers chunk and merge the results.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $categoryOptions
     * @return list<array<string, mixed>>
     */
    private function generateBatch(array $groups, array $categoryOptions): array
    {
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
