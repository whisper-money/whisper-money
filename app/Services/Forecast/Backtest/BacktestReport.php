<?php

namespace App\Services\Forecast\Backtest;

/**
 * Sums up backtest observations into aggregate figures per series and
 * horizon, the only thing the backtest ever shows.
 *
 * - `medianError`: the median of |actual - forecast| / |actual|, over the
 *   observations whose actual is large enough to divide by.
 * - `p10` / `p90`: percentiles of actual / forecast, over the observations
 *   whose forecast is a clear positive figure.
 * - `insideBand`: the share of actuals within the forecast's band, for the
 *   series that have one.
 * - `weightedError`: sum |actual - forecast| / sum |actual| (WAPE).
 */
class BacktestReport
{
    /**
     * @var array<string, array<int, list<BacktestObservation>>>
     */
    private array $observations = [];

    /**
     * @param  list<BacktestObservation>  $observations
     */
    public function add(array $observations): void
    {
        foreach ($observations as $observation) {
            $this->observations[$observation->series][$observation->horizon][] = $observation;
        }
    }

    /**
     * @param  list<string>  $series  In the order to report them.
     * @return list<array{series: string, horizon: int, count: int, medianError: float|null, p10: float|null, p90: float|null, insideBand: float|null, weightedError: float|null}>
     */
    public function rows(array $series): array
    {
        $rows = [];

        foreach ($series as $name) {
            $byHorizon = $this->observations[$name] ?? [];
            ksort($byHorizon);

            foreach ($byHorizon as $horizon => $observations) {
                $rows[] = ['series' => $name, 'horizon' => $horizon, ...self::summary($observations)];
            }
        }

        return $rows;
    }

    /**
     * @param  list<BacktestObservation>  $observations
     * @return array{count: int, medianError: float|null, p10: float|null, p90: float|null, insideBand: float|null, weightedError: float|null}
     */
    private static function summary(array $observations): array
    {
        $errors = self::present(array_map(fn (BacktestObservation $observation): ?float => $observation->absolutePercentError(), $observations));
        $ratios = self::present(array_map(fn (BacktestObservation $observation): ?float => $observation->actualOverForecast(), $observations));
        $banded = array_values(array_filter($observations, fn (BacktestObservation $observation): bool => $observation->band !== null));
        $actual = array_sum(array_map(fn (BacktestObservation $observation): int => abs($observation->actual), $observations));

        return [
            'count' => count($observations),
            'medianError' => self::percentile($errors, 0.5),
            'p10' => self::percentile($ratios, 0.1),
            'p90' => self::percentile($ratios, 0.9),
            'insideBand' => $banded === [] ? null : count(array_filter($banded, fn (BacktestObservation $observation): bool => $observation->isInsideBand())) / count($banded),
            'weightedError' => $actual === 0 ? null : array_sum(array_map(fn (BacktestObservation $observation): int => abs($observation->actual - $observation->forecast), $observations)) / $actual,
        ];
    }

    /**
     * @param  list<float|null>  $values
     * @return list<float>
     */
    private static function present(array $values): array
    {
        return array_values(array_filter($values, fn (?float $value): bool => $value !== null));
    }

    /**
     * The percentile by linear interpolation between the closest ranks.
     *
     * @param  list<float>  $values
     */
    private static function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $rank = $percentile * (count($values) - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        return $values[$lower] + ($values[$upper] - $values[$lower]) * ($rank - $lower);
    }
}
