<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastEstimateBasis;

/**
 * The estimated rest of one parent category on one account in a `Y-m` month:
 * spend expected beyond the concrete lines, in positive minor units of the
 * account's currency. `categoryId` is null for "Other spending", which also
 * gathers uncategorized spend and the categories too small for a row of their
 * own. `basis` says where the figure comes from: the median of recent months,
 * a budget that fits the category, or the user's own adjustment. In the month
 * the projection starts in it is what is left of it.
 *
 * `planned` is the month's whole figure before what was spent, `spent` what
 * the start month already spent (zero in every later month), and
 * `budgetAmount` this account's share of the budget the figure stands on,
 * when it stands on one. The page shows them as "spent X of Y".
 *
 * The forecast page of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class CategoryEstimate
{
    public function __construct(
        public string $accountId,
        public ?string $categoryId,
        public string $month,
        public int $amount,
        public ForecastEstimateBasis $basis,
        public ?int $planned = null,
        public int $spent = 0,
        public ?int $budgetAmount = null,
    ) {}

    public function toMonthlyEstimate(): MonthlyEstimate
    {
        return new MonthlyEstimate($this->accountId, $this->month, $this->amount);
    }
}
