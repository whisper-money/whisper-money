<?php

namespace App\Services\Stats;

/**
 * Welch's two-sample test for a difference in means with unequal variances — the
 * correct test for comparing contribution-margin-per-user across price arms. That
 * metric is heavily zero-inflated and, because a higher-priced arm books several
 * times the revenue variance of the control at equal conversion, a pooled-variance
 * t-test is invalid here. The two-sided p-value uses the normal approximation to
 * Student's t, accurate at the per-arm sample sizes this experiment reaches
 * (n ≳ 100 by the central limit theorem); the Satterthwaite degrees of freedom are
 * returned for context.
 *
 * ponytail: normal-approx p-value, exact only for large n. If a future experiment
 * needs it at small n, swap Normal::cdf() for a Student-t CDF (regularised
 * incomplete beta) — the statistic and df here are already exact.
 */
final class WelchTTest
{
    /**
     * @param  float  $varA  sample variance (n−1 denominator)
     * @param  float  $varB  sample variance (n−1 denominator)
     * @return array{diff: float, se: float, t: float, df: float, p: float}
     */
    public function compare(float $meanA, float $varA, int $nA, float $meanB, float $varB, int $nB): array
    {
        $seSq = ($nA > 0 ? $varA / $nA : 0.0) + ($nB > 0 ? $varB / $nB : 0.0);
        $se = sqrt($seSq);
        $diff = $meanA - $meanB;

        if ($se <= 0.0 || $nA < 2 || $nB < 2) {
            return ['diff' => $diff, 'se' => $se, 't' => 0.0, 'df' => 0.0, 'p' => 1.0];
        }

        $t = $diff / $se;
        $df = ($seSq ** 2) / (
            (($varA / $nA) ** 2) / ($nA - 1)
            + (($varB / $nB) ** 2) / ($nB - 1)
        );
        $p = 2.0 * (1.0 - Normal::cdf(abs($t)));

        return ['diff' => $diff, 'se' => $se, 't' => $t, 'df' => $df, 'p' => $p];
    }
}
