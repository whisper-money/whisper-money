<?php

namespace App\Services\Forecast\Backtest;

/**
 * One figure the forecast (or a baseline) gave at a cut-off next to what
 * really happened, with nothing that tells whose it is: the backtest only
 * ever reports these in aggregate.
 */
final readonly class BacktestObservation
{
    /**
     * @param  string  $series  What is compared, one of the BacktestSeries constants.
     * @param  int  $horizon  Months after the cut-off: the close of that month, or its flow.
     * @param  int|null  $band  The forecast's band around it, when it has one.
     * @param  int  $floor  Below this (in absolute value) a figure is too small for a percentage.
     */
    public function __construct(
        public string $series,
        public int $horizon,
        public int $forecast,
        public int $actual,
        public ?int $band,
        private int $floor,
    ) {}

    public function isInsideBand(): bool
    {
        return abs($this->actual - $this->forecast) <= (int) $this->band;
    }

    /**
     * The error as a share of what really happened, or null when that is too
     * small to divide by.
     */
    public function absolutePercentError(): ?float
    {
        return abs($this->actual) < $this->floor ? null : abs($this->actual - $this->forecast) / abs($this->actual);
    }

    /**
     * What really happened over what was forecast, or null when the forecast
     * is not a clear positive figure.
     */
    public function actualOverForecast(): ?float
    {
        return $this->forecast < $this->floor ? null : $this->actual / $this->forecast;
    }
}
