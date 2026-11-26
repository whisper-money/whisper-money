<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastEstimateBasis;

/**
 * One row of an account's estimated rest before it is spread over the
 * months: its median spend, the budget share it stands on instead (the
 * budget's monthly limit and this account's part of it), and what it spent
 * so far in the start month. Positive minor units of the account's currency.
 */
final readonly class EstimateRow
{
    public function __construct(
        public int $median,
        public int $spentNow,
        public ?int $budgetLimit = null,
        private ?float $budgetShare = null,
    ) {}

    /**
     * The row with a small one folded into it. Only median rows fold, so
     * this row keeps its own budget.
     */
    public function foldedWith(self $row): self
    {
        return new self($this->median + $row->median, $this->spentNow + $row->spentNow, $this->budgetLimit, $this->budgetShare);
    }

    public function basis(): ForecastEstimateBasis
    {
        return $this->budgetLimit === null ? ForecastEstimateBasis::Median : ForecastEstimateBasis::Budget;
    }

    /**
     * This account's share of the budget's monthly limit, or null when the
     * row stands on its median.
     */
    public function budgetAmount(): ?int
    {
        return $this->budgetLimit === null ? null : (int) floor($this->budgetLimit * ($this->budgetShare ?? 0));
    }

    /**
     * The month's figure before any adjustment: the median, or this
     * account's share of the budget less the concrete lines of the month,
     * never below zero.
     */
    public function amountFor(int $concreteSpend): int
    {
        if ($this->budgetLimit === null) {
            return $this->median;
        }

        return (int) floor(max($this->budgetLimit - $concreteSpend, 0) * ($this->budgetShare ?? 0));
    }
}
