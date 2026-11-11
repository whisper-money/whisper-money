<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\AggregateTransactionsRequest;
use App\Http\Requests\Api\V1\AnalyticsRangeRequest;
use App\Http\Requests\Api\V1\CashflowRequest;
use App\Http\Requests\Api\V1\NetWorthRequest;
use App\Http\Requests\Api\V1\SpendingByCategoryRequest;
use App\Http\Resources\V1\CashflowResource;
use App\Http\Resources\V1\CreditCardUsageResource;
use App\Http\Resources\V1\MonthlySavingsResource;
use App\Http\Resources\V1\NetWorthResource;
use App\Http\Resources\V1\SpendingByCategoryResource;
use App\Http\Resources\V1\SpendingByLabelResource;
use App\Http\Resources\V1\TransactionAggregateResource;
use App\Services\CashflowAnalyticsService;
use App\Services\CategorySpendingService;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\LabelSpendingService;
use App\Services\NetWorthAnalyticsService;
use App\Services\SavingsGoals\CurrentMonthSavings;
use App\Services\Transactions\TransactionAggregator;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Analytics', 'Figures rolled up from your transactions, balances and goals: cashflow, net worth, spending by category and by label, credit card usage and this month\'s savings, which cover your whole account rather than a space, with every amount in minor units of the `currency` given. Aggregates read your own transactions in a space instead, and give each currency its own bucket.', weight: 100)]
class AnalyticsController extends Controller
{
    /**
     * Retrieve the cashflow
     *
     * The cashflow of a date range: income, expenses, savings and investments
     * against the previous period, your expenses split by the budgets that
     * track them (fixed, variable and general), what your budgets still
     * reserve, where the money came from and went by category, and the
     * monthly trend. The range can touch at most 24 calendar months.
     */
    public function cashflow(CashflowRequest $request, CashflowAnalyticsService $cashflow): CashflowResource
    {
        $period = $request->period();

        return new CashflowResource($cashflow->forRange($this->user($request), $period->from, $period->to));
    }

    /**
     * Retrieve the net worth
     *
     * Your net worth at the end of a date range against the end of the
     * previous period, and every account's balance over the range, monthly or
     * daily. A monthly range can touch at most 120 calendar months, a daily
     * one cover at most 366 days.
     */
    public function netWorth(NetWorthRequest $request, NetWorthAnalyticsService $netWorth): NetWorthResource
    {
        return new NetWorthResource($netWorth->report($this->user($request), $request->period(), $request->daily()));
    }

    /**
     * Retrieve spending by category
     *
     * Your expenses in a date range by top-level category, each with its
     * subcategories rolled into it. Pass `parent_category_id` to drill into a
     * category: its children come back instead, plus a row for what was booked
     * to the category itself (`is_direct`). The range can touch at most 120
     * calendar months.
     */
    public function spendingByCategory(SpendingByCategoryRequest $request, CategorySpendingService $spending): SpendingByCategoryResource
    {
        $period = $request->period();

        return new SpendingByCategoryResource(
            $spending->forPeriod($this->user($request)->id, $period->from, $period->to, $request->parentCategoryId()),
        );
    }

    /**
     * Retrieve spending by label
     *
     * Your expenses in a date range by label, largest first, each against
     * the previous period. A transaction counts under every label it
     * carries. The labels of savings goals are left out: they track
     * contributions, not spending. The range can touch at most 120 calendar
     * months.
     */
    public function spendingByLabel(AnalyticsRangeRequest $request, LabelSpendingService $spending): SpendingByLabelResource
    {
        $period = $request->period();

        return new SpendingByLabelResource([
            'period' => $period,
            'labels' => $spending->comparedForPeriod($this->user($request)->id, $period),
        ]);
    }

    /**
     * Retrieve credit card usage
     *
     * How much of each of your credit cards is in use, archived cards left
     * out: what is still to be charged, what is left of the limit and how
     * that built up day by day. Each card's amounts are in its own currency.
     */
    public function creditCardUsage(Request $request, CreditCardStatementService $statements): AnonymousResourceCollection
    {
        return CreditCardUsageResource::collection($statements->liveUsageFor($this->user($request)));
    }

    /**
     * Retrieve this month's savings
     *
     * The month in progress across your monthly savings goals that are not
     * archived: what they saved so far against their targets, each goal's
     * month, and how every monthly goal did last month.
     */
    public function monthlySavings(Request $request, CurrentMonthSavings $savings): MonthlySavingsResource
    {
        return new MonthlySavingsResource($savings->forUser($this->user($request)));
    }

    /**
     * Aggregate transactions
     *
     * Your transactions in a space over a date range, grouped by up to two
     * dimensions and added up: their sum, count, average, smallest and
     * largest amount. As in the app, another member's transactions are left
     * out. Each bucket is also split by currency, so amounts in different
     * currencies are never added together, and every amount is your share of
     * the account, in minor units of the bucket's currency. A transaction
     * booked on an archived account stops counting from the day it was
     * archived. Every type of transaction counts unless `types` narrows it:
     * pass `types=expense` for spending, since transfers, savings and
     * investments otherwise add in too. Grouped by a period, every period of
     * the range comes back, with zeros where nothing was booked. A response
     * holds at most 1000 buckets: one that would hold more is a `422` under
     * `group_by`.
     */
    #[ScopedToSpace]
    public function aggregate(AggregateTransactionsRequest $request, TransactionAggregator $aggregator): TransactionAggregateResource
    {
        return new TransactionAggregateResource(
            $aggregator->aggregate($this->user($request), $this->space($request), $request->validated()),
        );
    }
}
