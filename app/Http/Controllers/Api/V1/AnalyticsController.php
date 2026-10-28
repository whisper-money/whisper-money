<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\AnalyticsRangeRequest;
use App\Http\Requests\Api\V1\NetWorthRequest;
use App\Http\Requests\Api\V1\SpendingByCategoryRequest;
use App\Http\Resources\V1\CashflowResource;
use App\Http\Resources\V1\NetWorthResource;
use App\Http\Resources\V1\SpendingByCategoryResource;
use App\Services\CashflowAnalyticsService;
use App\Services\CategorySpendingService;
use App\Services\NetWorthAnalyticsService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Analytics', 'Figures rolled up from your transactions and balances over a date range: cashflow, net worth and spending by category. They cover your whole account, not a space, and every amount is in the minor units of your account\'s currency, given as `currency`.', weight: 100)]
class AnalyticsController extends Controller
{
    /**
     * Retrieve the cashflow
     *
     * The cashflow of a date range: income, expenses, savings and investments
     * against the previous period, your expenses split by the budgets that
     * track them (fixed, variable and general), what your budgets still
     * reserve, where the money came from and went by category, and the
     * monthly trend.
     */
    public function cashflow(AnalyticsRangeRequest $request, CashflowAnalyticsService $cashflow): CashflowResource
    {
        $period = $request->period();

        return new CashflowResource($cashflow->forRange($this->user($request), $period->from, $period->to));
    }

    /**
     * Retrieve the net worth
     *
     * Your net worth at the end of a date range against the end of the
     * previous period, and every account's balance over the range, monthly or
     * daily.
     */
    public function netWorth(NetWorthRequest $request, NetWorthAnalyticsService $netWorth): NetWorthResource
    {
        $user = $this->user($request);
        $period = $request->period();
        $daily = $request->daily();

        return new NetWorthResource([
            'granularity' => $daily ? 'daily' : 'monthly',
            'compared' => $netWorth->compared($user, $period),
            'evolution' => $netWorth->evolution($user, $period->from, $period->to, $daily),
        ]);
    }

    /**
     * Retrieve spending by category
     *
     * Your expenses in a date range by top-level category, each with its
     * subcategories rolled into it. Pass `parent_category_id` to drill into a
     * category: its children come back instead, plus a row for what was booked
     * to the category itself (`is_direct`).
     */
    public function spendingByCategory(SpendingByCategoryRequest $request, CategorySpendingService $spending): SpendingByCategoryResource
    {
        $period = $request->period();

        return new SpendingByCategoryResource(
            $spending->forPeriod($this->user($request)->id, $period->from, $period->to, $request->parentCategoryId()),
        );
    }
}
