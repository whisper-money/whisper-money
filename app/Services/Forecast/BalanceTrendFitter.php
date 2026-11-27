<?php

namespace App\Services\Forecast;

use App\Services\Forecast\Data\BalanceTrend;

/**
 * Fits an ordinary least-squares line through month-end balances. The slope
 * is worked out in integers and rounded half away from zero to whole minor
 * units, so it never drifts with float precision; the band is the
 * population standard deviation of the residuals, rounded the same way.
 */
final class BalanceTrendFitter
{
    /**
     * @param  array<int, int>  $balances  Month-end balance by month index (0 is the oldest month); missing months are left out.
     */
    public function fit(array $balances): BalanceTrend
    {
        $points = count($balances);

        if ($points < BalanceTrend::MIN_POINTS) {
            return new BalanceTrend($points);
        }

        $sumX = array_sum(array_keys($balances));
        $sumY = array_sum($balances);
        $sumXX = 0;
        $sumXY = 0;

        foreach ($balances as $x => $y) {
            $sumXX += $x * $x;
            $sumXY += $x * $y;
        }

        $denominator = $points * $sumXX - $sumX * $sumX;
        $numerator = $points * $sumXY - $sumX * $sumY;

        return new BalanceTrend(
            $points,
            $this->roundedDivision($numerator, $denominator),
            $this->residualDeviation($balances, $numerator / $denominator),
        );
    }

    /**
     * The quotient rounded half away from zero; the divisor is positive.
     */
    private function roundedDivision(int $dividend, int $divisor): int
    {
        $sign = $dividend < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($dividend) + $divisor, 2 * $divisor);
    }

    /**
     * @param  array<int, int>  $balances
     */
    private function residualDeviation(array $balances, float $slope): int
    {
        $count = count($balances);
        $meanX = array_sum(array_keys($balances)) / $count;
        $meanY = array_sum($balances) / $count;
        $squares = 0.0;

        foreach ($balances as $x => $y) {
            $residual = $y - $meanY - $slope * ($x - $meanX);
            $squares += $residual * $residual;
        }

        return (int) round(sqrt($squares / $count));
    }
}
