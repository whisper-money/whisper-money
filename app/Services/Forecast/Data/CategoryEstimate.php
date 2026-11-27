<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastEstimateBasis;
use App\Enums\ForecastEstimateKind;

/**
 * What one parent category is expected to move on one account in a `Y-m`
 * month beyond the concrete lines, in positive minor units of the
 * account's currency: money in when `kind` is income, spend otherwise.
 * `categoryId` is null for "Other income" or "Other spending", which also
 * gather uncategorized money and the categories too small for a row of
 * their own. `basis` says where the figure comes from: the median of
 * recent months, a budget that fits the category (spend only), or the
 * user's own adjustment. In the month the projection starts in it is what
 * is left of it.
 *
 * `planned` is the month's whole figure before what already happened,
 * `spent` what the start month already spent or received (zero in every
 * later month), and `budgetAmount` this account's share of the budget the
 * figure stands on, when it stands on one. The page shows them as "spent X
 * of Y". `day` and `profile` are its timing inside the month (see
 * MonthlyEstimate).
 *
 * The tests read every field; the page and the projector read most of them.
 *
 * @api
 */
final readonly class CategoryEstimate
{
    /**
     * @param  list<int>|null  $profile
     */
    public function __construct(
        public string $accountId,
        public ?string $categoryId,
        public string $month,
        public int $amount,
        public ForecastEstimateBasis $basis,
        public ?int $planned = null,
        public int $spent = 0,
        public ?int $budgetAmount = null,
        public ForecastEstimateKind $kind = ForecastEstimateKind::Expense,
        public ?int $day = null,
        public ?array $profile = null,
    ) {}

    public function isIncome(): bool
    {
        return $this->kind === ForecastEstimateKind::Income;
    }

    /**
     * The amount as it moves the balance: positive for income, negative
     * for spend.
     */
    public function signedAmount(): int
    {
        return $this->isIncome() ? $this->amount : -$this->amount;
    }

    public function toMonthlyEstimate(): MonthlyEstimate
    {
        return new MonthlyEstimate($this->accountId, $this->month, $this->amount, $this->kind, $this->categoryId, $this->day, $this->profile);
    }
}
