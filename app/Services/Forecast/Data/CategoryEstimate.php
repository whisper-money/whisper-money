<?php

namespace App\Services\Forecast\Data;

/**
 * The estimated rest of one parent category on one account in a `Y-m` month:
 * spend expected beyond the concrete lines, in positive minor units of the
 * account's currency. `categoryId` is null for "Other spending", which also
 * gathers uncategorized spend and the categories too small for a row of their
 * own. `basis` says where the figure comes from: the median of recent months,
 * a budget that fits the category, or the user's own adjustment. In the month
 * the projection starts in it is what is left of it.
 *
 * The forecast page of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class CategoryEstimate
{
    public const BASIS_MEDIAN = 'median';

    public const BASIS_BUDGET = 'budget';

    public const BASIS_ADJUSTMENT = 'adjustment';

    /**
     * @param  self::BASIS_*  $basis
     */
    public function __construct(
        public string $accountId,
        public ?string $categoryId,
        public string $month,
        public int $amount,
        public string $basis,
    ) {}

    public function toMonthlyEstimate(): MonthlyEstimate
    {
        return new MonthlyEstimate($this->accountId, $this->month, $this->amount);
    }
}
