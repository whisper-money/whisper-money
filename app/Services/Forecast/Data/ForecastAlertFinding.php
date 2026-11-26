<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastAlertLevel;

/**
 * A low-balance problem the evaluator found in one account's projected month.
 * `belowMinimum` tells a balance that stays above zero but under the minimum
 * the owner wants to keep from one that goes below zero.
 */
final readonly class ForecastAlertFinding
{
    public function __construct(
        public string $accountId,
        public string $month,
        public ForecastAlertLevel $level,
        public bool $belowMinimum,
    ) {}

    /**
     * The key a stored alert is found by: one per account and month.
     */
    public function key(): string
    {
        return self::keyFor($this->accountId, $this->month);
    }

    /**
     * @param  string  $month  `Y-m`.
     */
    public static function keyFor(string $accountId, string $month): string
    {
        return $accountId.'|'.$month;
    }
}
