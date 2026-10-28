<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cashflow of a date range across your whole account: the summary against
 * the previous period, where the money came from and went, and the monthly
 * trend.
 *
 * @property array{summary: array{current: array<string, mixed>, previous: array<string, mixed>}, sankey: array<string, mixed>, trend: array{data: list<array{month: string, income: int, expense: int, net: int}>}} $resource
 */
#[SchemaName('Cashflow')]
class CashflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $sankey = $this->resource['sankey'];

        return [
            /**
             * The currency of every amount: your account's currency, as an ISO 4217 code.
             *
             * @example EUR
             */
            'currency' => $user->currency_code ?? 'USD',
            'summary' => [
                /**
                 * The range's cashflow.
                 */
                'current' => new CashflowPeriodResource($this->resource['summary']['current']),
                /**
                 * The cashflow of the period before it, to compare with: the same number of days right before `from`, or the same number of whole months when the range is whole months. It has the expense split but no `reserved` or `budgets`, which are about today.
                 */
                'previous' => new CashflowPeriodResource($this->resource['summary']['previous']),
            ],
            'sankey' => [
                /**
                 * Where the money came from, by top-level category. A category is netted and lands on the side its net points at, so an expense category that got more back than it spent is listed here as a refund. Savings and investment withdrawals and transfers set to count as an inflow appear here too, and the uncategorized money that came in is one row with no `category_id`.
                 */
                'income_categories' => CategoryAmountResource::collection($sankey['income_categories']),
                /**
                 * Where the money went, by top-level category, built the same way. Savings, investments and transfers set to count as an outflow appear here too.
                 */
                'expense_categories' => CategoryAmountResource::collection($sankey['expense_categories']),
                /**
                 * What `income_categories` adds up to.
                 *
                 * @var int
                 *
                 * @example 260000
                 */
                'total_income' => $sankey['total_income'],
                /**
                 * What `expense_categories` adds up to.
                 *
                 * @var int
                 *
                 * @example 106122
                 */
                'total_expense' => $sankey['total_expense'],
            ],
            /**
             * Income, expense and net per calendar month the range touches, oldest first, at most the last 24 months. Counted like `current`: savings, investments and transfers left out.
             *
             * @var list<array{month: string, income: int, expense: int, net: int}>
             *
             * @example [{"month": "2026-10", "income": 260000, "expense": 96122, "net": 163878}]
             */
            'trend' => $this->resource['trend']['data'],
        ];
    }
}
