<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastEstimateBasis;
use App\Enums\ForecastEstimateKind;

/**
 * One row of an account's estimates before it is spread over the months:
 * whether it is income or spend, its median, the budget share it stands on
 * instead (the budget's monthly limit and this account's part of it; spend
 * only), what the start month already moved, and the amounts it moved by
 * month and day of the month, which its timing is learnt from. Positive
 * minor units of the account's currency.
 */
final readonly class EstimateRow
{
    /**
     * @param  array<string, array<int, int>>  $byMonthAndDay  By `Y-m` month, then day of the month.
     */
    public function __construct(
        public int $median,
        public int $spentNow,
        public ?int $budgetLimit = null,
        private ?float $budgetShare = null,
        public ForecastEstimateKind $kind = ForecastEstimateKind::Expense,
        public array $byMonthAndDay = [],
    ) {}

    /**
     * The row with a small one folded into it. Only median rows fold, so
     * this row keeps its own budget.
     */
    public function foldedWith(self $row): self
    {
        $byMonthAndDay = $this->byMonthAndDay;

        foreach ($row->byMonthAndDay as $month => $days) {
            foreach ($days as $day => $amount) {
                $byMonthAndDay[$month][$day] = ($byMonthAndDay[$month][$day] ?? 0) + $amount;
            }
        }

        return new self($this->median + $row->median, $this->spentNow + $row->spentNow, $this->budgetLimit, $this->budgetShare, $this->kind, $byMonthAndDay);
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
