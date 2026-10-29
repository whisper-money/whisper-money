<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cashflow of one period, in minor units of `currency`. Income and
 * expenses are signed nets: a refund lowers the expense, and a period whose
 * refunds outweigh its spending has a negative one.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('CashflowPeriod')]
class CashflowPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * What came in: the transactions in income categories, and the uncategorized ones that brought money in.
             *
             * @var int
             *
             * @example 260000
             */
            'income' => $this->resource['income'],
            /**
             * What went out: the transactions in expense categories, and the uncategorized ones that took money out. Savings, investments and transfers are not in it.
             *
             * @var int
             *
             * @example 96122
             */
            'expense' => $this->resource['expense'],
            /**
             * `income` minus `expense`.
             *
             * @var int
             *
             * @example 163878
             */
            'net' => $this->resource['net'],
            /**
             * `net` as a percentage of `income`, rounded to one decimal and never above 100. `0` with no income.
             *
             * @var float
             *
             * @example 63
             */
            'savings_rate' => $this->resource['savings_rate'],
            /**
             * What was set aside into savings categories, net of withdrawals.
             *
             * @var int
             *
             * @example 10000
             */
            'savings' => $this->resource['savings'],
            /**
             * What was set aside into investment categories, net of withdrawals.
             *
             * @var int
             *
             * @example 0
             */
            'investments' => $this->resource['investments'],
            /**
             * The part of `expense` tracked by budgets marked fixed (rent, subscriptions). Budgets are variable unless marked fixed, so `0` may only mean none has been marked yet. A transaction tracked by a fixed and a variable budget counts here, once.
             *
             * @var int
             *
             * @example 90000
             */
            'fixed_expense' => $this->resource['fixed_expense'],
            /**
             * The part of `expense` tracked by budgets marked variable (groceries, leisure). Catch-all budgets do not count.
             *
             * @var int
             *
             * @example 5345
             */
            'variable_expense' => $this->resource['variable_expense'],
            /**
             * The rest of `expense`, which no budget tracks. The three parts add up to `expense`, and any can be negative in a period of refunds.
             *
             * @var int
             *
             * @example 777
             */
            'general_expense' => $this->resource['general_expense'],
            /**
             * Current period only: what the budgets in `budgets` still hold back for spending, adding up their positive margins. An over-budget period adds nothing.
             *
             * @var int
             *
             * @example 17950
             */
            'reserved' => $this->when(array_key_exists('reserved', $this->resource), fn (): mixed => $this->resource['reserved']),
            /**
             * Current period only: the budget periods in progress today that overlap the range. A range of up to 31 days counts weekly, bi-weekly and monthly budgets, one of up to 92 days those plus quarterly ones, and a longer one only yearly budgets.
             */
            'budgets' => $this->when(array_key_exists('budgets', $this->resource), fn (): mixed => CashflowBudgetResource::collection($this->resource['budgets'])),
        ];
    }
}
