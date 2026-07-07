<?php

namespace App\Services\Ai;

use App\Ai\Agents\RuleSuggestionAgent;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

class LaravelAiRuleSuggestionGenerator implements RuleSuggestionGenerator
{
    public function generate(array $groups, array $categoryOptions): array
    {
        if ($groups === []) {
            return [];
        }

        $batchSize = max(1, (int) config('ai_suggestions.group_batch_size'));
        $batches = array_chunk($groups, $batchSize);

        $suggestions = [];
        $failures = 0;
        $lastError = null;

        foreach ($batches as $batch) {
            try {
                foreach ($this->generateBatchWithRetry($batch, $categoryOptions) as $suggestion) {
                    $suggestions[] = $suggestion;
                }
            } catch (FailoverableException $exception) {
                // An overloaded or rate-limited provider is an expected transient
                // condition, not a bug (PHP-LARAVEL-44). Count it as a failure so
                // an all-transient run still surfaces, but don't report the
                // per-batch noise — a genuine total failure is still reported once
                // by the run-level handler. Mirrors CategorizeTransactions.
                $failures++;
                $lastError = $exception;
                Log::warning('AI rule-suggestion batch dropped: provider transient failure.', [
                    'exception' => $exception->getMessage(),
                ]);
            } catch (Throwable $exception) {
                // A single batch failing must not discard the suggestions from
                // the batches that did succeed (a run can span many batches).
                $failures++;
                $lastError = $exception;
                report($exception);
            }
        }

        // Only surface an error when every batch failed — otherwise a transient
        // hiccup would masquerade as "no suggestions" and silently swallow it.
        if ($lastError !== null && $failures === count($batches)) {
            throw $lastError;
        }

        return $suggestions;
    }

    /**
     * Send one batch, retrying once on a transient failure before giving up.
     *
     * @param  list<array<string, mixed>>  $groups
     * @param  list<array<string, mixed>>  $categoryOptions
     * @return list<array<string, mixed>>
     */
    private function generateBatchWithRetry(array $groups, array $categoryOptions): array
    {
        try {
            return $this->generateBatch($groups, $categoryOptions);
        } catch (Throwable) {
            return $this->generateBatch($groups, $categoryOptions);
        }
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
