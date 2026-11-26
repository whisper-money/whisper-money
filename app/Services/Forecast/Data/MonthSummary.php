<?php

namespace App\Services\Forecast\Data;

/**
 * One projected month of an account, or of all of them, built day by day.
 *
 * `min` is the lowest end-of-day balance and `close` the last one. `income`
 * and `expenses` leave transfers out; `expenses` includes the estimated spend,
 * which `estimated` reports on its own. `band` is the uncertainty of that
 * estimate alone (concrete lines have none), 40% of the month's estimate and
 * not carried over, and `pessimisticMin` the minimum less that band.
 */
final class MonthSummary
{
    /**
     * The band is BAND_NUMERATOR / BAND_DENOMINATOR (40%) of the estimate.
     */
    public const BAND_NUMERATOR = 2;

    public const BAND_DENOMINATOR = 5;

    public ?int $min = null;

    public ?string $minDate = null;

    public int $close = 0;

    public int $income = 0;

    public int $concreteExpenses = 0;

    public int $transfers = 0;

    public int $growth = 0;

    public int $estimated = 0;

    /**
     * End-of-day balance of each day of the month, keyed by date.
     *
     * @var array<string, int>
     */
    public array $balances = [];

    public function __construct(public readonly string $month) {}

    public function observe(string $date, int $balance): void
    {
        $this->balances[$date] = $balance;
        $this->close = $balance;

        if ($this->min === null || $balance < $this->min) {
            $this->min = $balance;
            $this->minDate = $date;
        }
    }

    /**
     * Records a day's concrete flows and returns what they change the balance by.
     *
     * @param  array{income: int, expenses: int, transfers: int}|null  $flow
     */
    public function addFlow(?array $flow): int
    {
        if ($flow === null) {
            return 0;
        }

        $this->income += $flow['income'];
        $this->concreteExpenses += $flow['expenses'];
        $this->transfers += $flow['transfers'];

        return $flow['income'] - $flow['expenses'] + $flow['transfers'];
    }

    /**
     * Adds another summary's money flows to this one; balances are observed
     * separately, since a total's minimum is not the sum of minimums.
     */
    public function absorbFlows(self $other): void
    {
        $this->income += $other->income;
        $this->concreteExpenses += $other->concreteExpenses;
        $this->transfers += $other->transfers;
        $this->growth += $other->growth;
        $this->estimated += $other->estimated;
    }

    public function band(): int
    {
        return intdiv($this->estimated * self::BAND_NUMERATOR, self::BAND_DENOMINATOR);
    }

    /**
     * @return array{month: string, min: int, minDate: string|null, close: int, income: int, expenses: int, transfers: int, growth: int, estimated: int, band: int, pessimisticMin: int}
     */
    public function toArray(): array
    {
        $min = $this->min ?? $this->close;

        return [
            'month' => $this->month,
            'min' => $min,
            'minDate' => $this->minDate,
            'close' => $this->close,
            'income' => $this->income,
            'expenses' => $this->concreteExpenses + $this->estimated,
            'transfers' => $this->transfers,
            'growth' => $this->growth,
            'estimated' => $this->estimated,
            'band' => $this->band(),
            'pessimisticMin' => $min - $this->band(),
        ];
    }
}
