<?php

namespace App\Services\Forecast\Data;

/**
 * Spend expected on an account in a `Y-m` month beyond its concrete lines, in
 * positive minor units, spread evenly over the month's days. For the month
 * the projection starts in it is what is left to spend, spread over the days
 * left. Several estimates for the same account and month add up.
 */
final readonly class MonthlyEstimate
{
    public function __construct(
        public string $accountId,
        public string $month,
        public int $amount,
    ) {}

    /**
     * @param  array{accountId: string, month: string, amount: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['accountId'], $data['month'], $data['amount']);
    }
}
