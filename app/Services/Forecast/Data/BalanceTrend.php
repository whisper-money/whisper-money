<?php

namespace App\Services\Forecast\Data;

/**
 * The straight line an account's month-end balances drew over the last 12
 * complete months, for an account the forecast cannot read movements from
 * (none in the last 90 days) but has balances for. `points` is how many of
 * those month ends had a balance; with fewer than MIN_POINTS there is no
 * line (`monthlySlope` and `band` are null) and the account is kept flat.
 * `monthlySlope` is what the line adds per month and `band` the standard
 * deviation of the balances around it, both in whole minor units.
 */
final readonly class BalanceTrend
{
    /**
     * The fewest month-end balances a line is drawn through.
     */
    public const MIN_POINTS = 4;

    public function __construct(
        private int $points,
        public ?int $monthlySlope = null,
        public ?int $band = null,
    ) {}

    public function isProjected(): bool
    {
        return $this->monthlySlope !== null;
    }

    /**
     * What the page says about it: a null `monthly` means "kept flat".
     *
     * @return array{monthly: int|null, band: int|null, points: int}
     */
    public function toArray(): array
    {
        return ['monthly' => $this->monthlySlope, 'band' => $this->band, 'points' => $this->points];
    }
}
