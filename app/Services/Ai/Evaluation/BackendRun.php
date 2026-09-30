<?php

namespace App\Services\Ai\Evaluation;

/**
 * What one backend answered for an evaluation sample, plus what it cost to ask:
 * tokens, per-call latency and the failures and retries along the way.
 */
final class BackendRun
{
    /** @var array<string, array{category_id: ?string, confidence: float, merchant_unambiguous: bool}> */
    public array $predictions = [];

    /** @var list<string> */
    public array $failed = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<float> */
    public array $callSeconds = [];

    public int $retries = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    public function __construct(
        public readonly string $backend,
        public readonly string $model,
    ) {}

    public function addUsage(int $inputTokens, int $outputTokens): void
    {
        $this->inputTokens += $inputTokens;
        $this->outputTokens += $outputTokens;
    }

    public function sent(): int
    {
        return count($this->predictions) + count($this->failed);
    }

    /**
     * Count the predictions with a category that pass the filter, and how many
     * of them match the expected category.
     *
     * @param  array<string, string>  $expected  transaction id => expected category id
     * @param  (callable(array{category_id: ?string, confidence: float, merchant_unambiguous: bool}): bool)|null  $filter
     * @return array{count: int, correct: int}
     */
    public function tally(array $expected, ?callable $filter = null): array
    {
        $count = 0;
        $correct = 0;

        foreach ($this->predictions as $ref => $prediction) {
            if ($prediction['category_id'] === null || ($filter !== null && ! $filter($prediction))) {
                continue;
            }

            $count++;
            $correct += (int) ($prediction['category_id'] === ($expected[$ref] ?? null));
        }

        return ['count' => $count, 'correct' => $correct];
    }

    /**
     * USD spent on the run, or null when the model has no configured price.
     */
    public function cost(): ?float
    {
        $pricing = config('ai_categorization.pricing')[$this->model] ?? null;

        if ($pricing === null) {
            return null;
        }

        return ($this->inputTokens * $pricing['input'] + $this->outputTokens * $pricing['output']) / 1_000_000;
    }

    /**
     * The per-call latency at the given percentile (0-1), in seconds.
     */
    public function latency(float $percentile): float
    {
        if ($this->callSeconds === []) {
            return 0.0;
        }

        $seconds = $this->callSeconds;
        sort($seconds);

        return $seconds[max(0, (int) ceil($percentile * count($seconds)) - 1)];
    }
}
