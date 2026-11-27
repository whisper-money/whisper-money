<?php

namespace App\Services\Forecast;

use App\Enums\ForecastEstimateKind;

/**
 * Builds one projected month of an account, or of all of them, day by day;
 * toArray() is what ForecastProjector returns for it.
 *
 * `min` is the lowest end-of-day balance and `close` the last one. `income`
 * and `expenses` leave transfers out; they include the estimated income and
 * spend, which `estimatedIncome` and `estimated` report on their own. `band`
 * is the uncertainty of those estimates alone (concrete lines have none):
 * 40% of the estimated spend plus 10% of the estimated income, not carried
 * over. `pessimisticMin` is the minimum less that band.
 */
final class MonthAccumulator
{
    /**
     * The band is EXPENSE_BAND_NUMERATOR / EXPENSE_BAND_DENOMINATOR (40%) of
     * the estimated spend plus 1 / INCOME_BAND_DENOMINATOR (10%) of the
     * estimated income.
     */
    private const EXPENSE_BAND_NUMERATOR = 2;

    private const EXPENSE_BAND_DENOMINATOR = 5;

    private const INCOME_BAND_DENOMINATOR = 10;

    private ?int $min = null;

    private ?string $minDate = null;

    public int $close = 0;

    public int $income = 0;

    public int $concreteExpenses = 0;

    public int $transfers = 0;

    public int $growth = 0;

    public int $estimated = 0;

    public int $estimatedIncome = 0;

    /**
     * End-of-day balance of each day of the month, keyed by date.
     *
     * @var array<string, int>
     */
    public array $balances = [];

    public function __construct(private readonly string $month) {}

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
        $this->estimatedIncome += $other->estimatedIncome;
    }

    public function addEstimate(ForecastEstimateKind $kind, int $amount): void
    {
        if ($kind === ForecastEstimateKind::Income) {
            $this->estimatedIncome += $amount;
        } else {
            $this->estimated += $amount;
        }
    }

    public function band(): int
    {
        return intdiv($this->estimated * self::EXPENSE_BAND_NUMERATOR, self::EXPENSE_BAND_DENOMINATOR)
            + intdiv($this->estimatedIncome, self::INCOME_BAND_DENOMINATOR);
    }

    /**
     * @return array{month: string, min: int, minDate: string|null, close: int, income: int, expenses: int, transfers: int, growth: int, estimated: int, estimatedIncome: int, band: int, pessimisticMin: int}
     */
    public function toArray(): array
    {
        $min = $this->min ?? $this->close;

        return [
            'month' => $this->month,
            'min' => $min,
            'minDate' => $this->minDate,
            'close' => $this->close,
            'income' => $this->income + $this->estimatedIncome,
            'expenses' => $this->concreteExpenses + $this->estimated,
            'transfers' => $this->transfers,
            'growth' => $this->growth,
            'estimated' => $this->estimated,
            'estimatedIncome' => $this->estimatedIncome,
            'band' => $this->band(),
            'pessimisticMin' => $min - $this->band(),
        ];
    }
}
