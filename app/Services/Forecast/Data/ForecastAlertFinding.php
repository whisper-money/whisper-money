<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastAlertLevel;

/**
 * A low-balance problem the evaluator found in one account's projected month.
 * `belowMinimum` tells a balance that stays above zero but under the minimum
 * the owner wants to keep from one that goes below zero. A strong finding
 * also carries what the dashboard's card says about it: the lowest balance,
 * the day it falls on and the charges behind it.
 */
final readonly class ForecastAlertFinding
{
    public function __construct(
        public string $accountId,
        public string $month,
        public ForecastAlertLevel $level,
        public bool $belowMinimum,
        public ?int $lowestBalance = null,
        public ?string $lowestOn = null,
        /** @var list<array{name: string, amount: int}> */
        public array $causes = [],
    ) {}

    /**
     * What a stored alert keeps of the finding for the dashboard's card.
     *
     * @return array{lowest_balance: int|null, lowest_on: string|null, causes: list<array{name: string, amount: int}>}
     */
    public function details(): array
    {
        return ['lowest_balance' => $this->lowestBalance, 'lowest_on' => $this->lowestOn, 'causes' => $this->causes];
    }

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
