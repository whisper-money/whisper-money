<?php

namespace App\Services\Forecast;

use App\Enums\ForecastEstimateBasis;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the review's "Day-to-day spending" box says: how the forecast
 * estimates next month's spend beyond the concrete lines.
 */
class ForecastDailySpend
{
    public function __construct(
        private readonly ForecastPlanBuilder $builder,
        private readonly ForecastBudgetTargets $budgets,
        private readonly ForecastHistory $history,
    ) {}

    /**
     * How next month's day-to-day spend is estimated, in the user's
     * currency: the parent categories standing on a budget, the largest one
     * standing on its median, and the budgets that could not be used.
     *
     * @param  Collection<string, Account>  $accounts
     * @return array{currencyCode: string, budgets: list<array{name: string, amount: int}>, median: array{name: string, amount: int}|null, unusableBudgets: list<string>}
     */
    public function for(User $user, string $spaceId, string $today, Collection $accounts): array
    {
        $month = ForecastCalendar::monthKey(ForecastCalendar::monthStart($today, 1));
        $plan = $this->builder->build($user, $spaceId, null, 2, $today);
        $byCategory = [];

        foreach ($plan->categoryEstimates as $estimate) {
            $inUserCurrency = strcasecmp((string) $accounts->get($estimate->accountId)?->currency_code, (string) $user->currency_code) === 0;

            if ($estimate->month === $month && $estimate->categoryId !== null && ! $estimate->isIncome() && $inUserCurrency) {
                $byCategory[$estimate->categoryId][] = $estimate;
            }
        }

        $names = Category::query()->whereIn('id', array_keys($byCategory))->pluck('name', 'id');
        $rows = collect($byCategory)->map(fn (array $estimates, string $categoryId): array => [
            'name' => (string) $names->get($categoryId),
            'basis' => $estimates[0]->basis,
            'amount' => array_sum(array_map(fn (CategoryEstimate $estimate): int => $estimate->basis === ForecastEstimateBasis::Budget ? (int) $estimate->budgetAmount : $estimate->amount, $estimates)),
        ]);
        $median = $rows->where('basis', ForecastEstimateBasis::Median)->sortByDesc('amount')->first();

        return [
            'currencyCode' => (string) $user->currency_code,
            'budgets' => $rows->where('basis', ForecastEstimateBasis::Budget)->sortByDesc('amount')->map(fn (array $row): array => ['name' => $row['name'], 'amount' => $row['amount']])->values()->all(),
            'median' => $median === null ? null : ['name' => $median['name'], 'amount' => $median['amount']],
            'unusableBudgets' => $this->budgets->unusableNames($user, $spaceId, $this->history->parentCategories($user)),
        ];
    }
}
