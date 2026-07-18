<?php

namespace App\Services\Stats;

/**
 * Standard-normal cumulative distribution, shared by the significance tests that
 * need a normal tail probability (Welch's t via the large-sample approximation,
 * and the χ²₁ sample-ratio-mismatch check). One implementation so the erf
 * approximation lives in a single place.
 */
final class Normal
{
    public static function cdf(float $x): float
    {
        return 0.5 * (1.0 + self::erf($x / sqrt(2.0)));
    }

    /** Abramowitz & Stegun 7.1.26 — |error| < 1.5e-7. */
    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);
        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $y = 1.0 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $sign * $y;
    }
}
