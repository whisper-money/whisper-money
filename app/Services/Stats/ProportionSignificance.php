<?php

namespace App\Services\Stats;

/**
 * Frequentist inference on binomial proportions for the experiment funnel:
 * per-arm Wilson intervals, a Newcombe interval for the difference of two
 * proportions, and a Fisher exact test for the leader-vs-runner-up comparison.
 *
 * Fisher (exact at any sample size) is the decision test because the report's
 * conversion counts are too small for the two-proportion z normal approximation
 * to be valid — its expected cell counts fall well below the np>=5 rule.
 */
final class ProportionSignificance
{
    /** Two-sided 95% standard-normal quantile. */
    private const Z_95 = 1.96;

    /** @var list<float> memoised log-factorials, index i = log(i!) */
    private array $logFactorials = [0.0, 0.0];

    /**
     * Compare the two leading arms: Newcombe difference interval, Fisher exact
     * p-value, and a family-wise (Bonferroni) corrected significance flag. `z`
     * and `minExpectedCount` are returned for the small-sample caveat only.
     *
     * @return array{alpha: float, diffLow: float, diffHigh: float, fisherP: float, significant: bool, minExpectedCount: float, z: float}
     */
    public function compare(BinomialProportion $leader, BinomialProportion $runnerUp, float $familyAlpha = 0.05, int $comparisons = 3): array
    {
        $alpha = $familyAlpha / $comparisons;
        [$diffLow, $diffHigh] = $this->newcombeDiffInterval($leader, $runnerUp);
        $fisherP = $this->fisherExactTwoSided(
            $leader->successes, $leader->trials - $leader->successes,
            $runnerUp->successes, $runnerUp->trials - $runnerUp->successes,
        );

        $pooled = ($leader->successes + $runnerUp->successes) / ($leader->trials + $runnerUp->trials);
        $minExpectedCount = min($leader->trials, $runnerUp->trials) * min($pooled, 1 - $pooled);

        return [
            'alpha' => $alpha,
            'diffLow' => $diffLow,
            'diffHigh' => $diffHigh,
            'fisherP' => $fisherP,
            'significant' => $fisherP < $alpha,
            'minExpectedCount' => $minExpectedCount,
            'z' => $this->twoProportionZ($leader, $runnerUp),
        ];
    }

    /**
     * Wilson score interval for a binomial proportion — accurate for small n
     * and near 0/1, where the normal approximation misbehaves.
     *
     * @return array{0: float, 1: float} lower and upper bound, clamped to [0, 1]
     */
    public function wilsonInterval(int $successes, int $trials, float $z = self::Z_95): array
    {
        $p = $successes / $trials;
        $z2 = $z * $z;
        $denom = 1 + $z2 / $trials;
        $center = ($p + $z2 / (2 * $trials)) / $denom;
        $margin = ($z / $denom) * sqrt($p * (1 - $p) / $trials + $z2 / (4 * $trials * $trials));

        return [max(0.0, $center - $margin), min(1.0, $center + $margin)];
    }

    /**
     * Newcombe (Wilson-based) 95% interval for the difference pA − pB. The
     * correct object for "is A better than B": overlapping marginal intervals do
     * NOT imply the difference includes 0.
     *
     * @return array{0: float, 1: float}
     */
    public function newcombeDiffInterval(BinomialProportion $a, BinomialProportion $b, float $z = self::Z_95): array
    {
        $pA = $a->rate();
        $pB = $b->rate();
        [$lA, $uA] = $this->wilsonInterval($a->successes, $a->trials, $z);
        [$lB, $uB] = $this->wilsonInterval($b->successes, $b->trials, $z);

        $lower = ($pA - $pB) - sqrt(($pA - $lA) ** 2 + ($uB - $pB) ** 2);
        $upper = ($pA - $pB) + sqrt(($uA - $pA) ** 2 + ($pB - $lB) ** 2);

        return [$lower, $upper];
    }

    /** Pooled two-proportion z statistic — descriptive only, not the decision test. */
    public function twoProportionZ(BinomialProportion $a, BinomialProportion $b): float
    {
        $pooled = ($a->successes + $b->successes) / ($a->trials + $b->trials);
        $se = sqrt($pooled * (1 - $pooled) * (1 / $a->trials + 1 / $b->trials));

        return $se > 0.0 ? ($a->rate() - $b->rate()) / $se : 0.0;
    }

    /**
     * Two-sided Fisher exact p-value for the 2x2 table [[a, b], [c, d]]
     * (a/c = successes, b/d = failures). Sums the hypergeometric probabilities
     * of every same-margin table no more likely than the observed one. Exact at
     * any sample size — no normal approximation.
     */
    public function fisherExactTwoSided(int $a, int $b, int $c, int $d): float
    {
        $rowA = $a + $b;
        $rowB = $c + $d;
        $col = $a + $c;
        $total = $rowA + $rowB;

        if ($rowA === 0 || $rowB === 0 || $col === 0 || $col === $total) {
            return 1.0;
        }

        $logProbObserved = $this->hypergeometricLogProb($a, $rowA, $rowB, $col);
        $p = 0.0;
        for ($x = max(0, $col - $rowB); $x <= min($col, $rowA); $x++) {
            $logProb = $this->hypergeometricLogProb($x, $rowA, $rowB, $col);
            if ($logProb <= $logProbObserved + 1e-7) {
                $p += exp($logProb);
            }
        }

        return min(1.0, $p);
    }

    private function hypergeometricLogProb(int $x, int $rowA, int $rowB, int $col): float
    {
        return $this->logChoose($rowA, $x) + $this->logChoose($rowB, $col - $x) - $this->logChoose($rowA + $rowB, $col);
    }

    private function logChoose(int $n, int $k): float
    {
        if ($k < 0 || $k > $n) {
            return -INF;
        }

        return $this->logFactorial($n) - $this->logFactorial($k) - $this->logFactorial($n - $k);
    }

    private function logFactorial(int $n): float
    {
        for ($i = count($this->logFactorials); $i <= $n; $i++) {
            $this->logFactorials[$i] = $this->logFactorials[$i - 1] + log($i);
        }

        return $this->logFactorials[$n];
    }
}
