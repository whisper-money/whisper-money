<?php

namespace App\Services\Stats;

/**
 * Sample-ratio-mismatch (SRM) check for a two-arm experiment: a χ² goodness-of-fit
 * test (1 degree of freedom) of the observed arm counts against an even 1:1 split.
 * A low p means the assignment is not really 50/50 — a data-pipeline or attrition
 * bug that invalidates every downstream comparison, so it must be checked before
 * trusting the funnel. Run it on the assigned counts AND on any filtered subset
 * (matured / activated) to catch differential attrition a deterministic assignment
 * can still produce.
 */
final class SampleRatioMismatch
{
    /**
     * @return array{chiSq: float, p: float}
     */
    public function evenSplit(int $countA, int $countB): array
    {
        $total = $countA + $countB;

        if ($total === 0) {
            return ['chiSq' => 0.0, 'p' => 1.0];
        }

        $expected = $total / 2.0;
        $chiSq = (($countA - $expected) ** 2 + ($countB - $expected) ** 2) / $expected;

        // χ² with 1 df is Z²; its upper tail is P(|Z| > √χ²).
        $p = 2.0 * (1.0 - Normal::cdf(sqrt($chiSq)));

        return ['chiSq' => $chiSq, 'p' => $p];
    }
}
