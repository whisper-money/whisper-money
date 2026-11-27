<?php

namespace App\Services\Forecast;

use App\Enums\ForecastEstimateBasis;
use App\Enums\ForecastEstimateKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\TransferEstimate;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the review's first step shows: how next month is estimated, for the
 * account being reviewed or for every included account. One row per
 * parent category and account, income first and then spend, each side's
 * "Other" last, with the figure in the account's currency and where it
 * comes from; the usual transfers between the user's accounts apart; and
 * the month's income, spend and net in the user's currency, converted at
 * today's rate. An account whose currency has no rate today is left out of
 * the totals and its currency listed, rather than added unconverted.
 * Transfers stay out of the totals: they move money between the user's own
 * accounts. The budgets the forecast cannot stand on are named too, since
 * their categories quietly fall back to the median.
 */
class ForecastReviewEstimates
{
    public function __construct(
        private readonly ForecastPlanBuilder $builder,
        private readonly ExchangeRateService $rates,
        private readonly ForecastBudgetTargets $budgets,
        private readonly ForecastHistory $history,
    ) {}

    /**
     * @param  Collection<string, Account>  $accounts  The included accounts, by id.
     * @return array{month: string, currencyCode: string, income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, transfers: list<array<string, mixed>>, totals: array{income: int, expenses: int, net: int}, unconvertedCurrencies: list<string>, unusableBudgets: list<string>}
     */
    public function for(User $user, string $spaceId, Collection $accounts, ?string $accountId, string $today): array
    {
        $month = ForecastCalendar::monthKey(ForecastCalendar::monthStart($today, 1));
        $plan = $this->builder->build($user, $spaceId, $accountId === null ? null : [$accountId], 2, $today);
        // A category the user set to zero stays, so the figure can be changed back.
        $estimates = array_values(array_filter($plan->categoryEstimates, fn (CategoryEstimate $estimate): bool => $estimate->month === $month
            && ($estimate->amount > 0 || $estimate->basis === ForecastEstimateBasis::Adjustment)));
        $names = Category::query()->whereIn('id', array_filter(array_map(fn (CategoryEstimate $estimate): ?string => $estimate->categoryId, $estimates)))->pluck('name', 'id');
        $rows = array_map(fn (CategoryEstimate $estimate): array => $this->row($estimate, $accounts, $names->get((string) $estimate->categoryId)), $estimates);
        $totals = $this->totals($user, $estimates, $accounts, $today);

        return [
            'month' => $month,
            'currencyCode' => (string) $user->currency_code,
            'income' => $this->sorted(array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === ForecastEstimateKind::Income->value))),
            'expenses' => $this->sorted(array_values(array_filter($rows, fn (array $row): bool => $row['kind'] === ForecastEstimateKind::Expense->value))),
            'transfers' => $this->transfers($plan->transferEstimates, $month, $accounts),
            'totals' => $totals['totals'],
            'unconvertedCurrencies' => $totals['unconverted'],
            'unusableBudgets' => $this->budgets->unusableNames($user, $spaceId, $this->history->parentCategories($user)),
        ];
    }

    /**
     * @param  Collection<string, Account>  $accounts
     * @return array<string, mixed>
     */
    private function row(CategoryEstimate $estimate, Collection $accounts, ?string $name): array
    {
        $account = $accounts->get($estimate->accountId);

        return [
            'accountId' => $estimate->accountId,
            'accountName' => $account?->name,
            'currencyCode' => $account?->currency_code,
            'categoryId' => $estimate->categoryId,
            'name' => $name,
            'kind' => $estimate->kind->value,
            'amount' => $estimate->amount,
            'basis' => $estimate->basis->value,
            'budgetAmount' => $estimate->budgetAmount,
        ];
    }

    /**
     * The largest first, "Other" last.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => [$a['categoryId'] === null, -$a['amount'], (string) $a['name']] <=> [$b['categoryId'] === null, -$b['amount'], (string) $b['name']]);

        return $rows;
    }

    /**
     * @param  list<TransferEstimate>  $estimates
     * @param  Collection<string, Account>  $accounts
     * @return list<array<string, mixed>>
     */
    private function transfers(array $estimates, string $month, Collection $accounts): array
    {
        $rows = [];

        foreach ($estimates as $estimate) {
            if ($estimate->month !== $month || $estimate->amount <= 0) {
                continue;
            }

            $rows[] = [
                'key' => $estimate->key(),
                'fromAccountName' => $estimate->fromAccountName,
                'toAccountName' => $estimate->toAccountName,
                'amount' => $estimate->amount,
                'currencyCode' => ($accounts->get($estimate->fromAccountId) ?? $accounts->get($estimate->toAccountId))?->currency_code,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $rows;
    }

    /**
     * @param  list<CategoryEstimate>  $estimates
     * @param  Collection<string, Account>  $accounts
     * @return array{totals: array{income: int, expenses: int, net: int}, unconverted: list<string>}
     */
    private function totals(User $user, array $estimates, Collection $accounts, string $today): array
    {
        $sums = ['income' => 0, 'expenses' => 0];
        $unconverted = [];

        foreach ($estimates as $estimate) {
            $currency = (string) $accounts->get($estimate->accountId)?->currency_code;
            $amount = $this->rates->convertOrNull($currency, (string) $user->currency_code, $estimate->amount, $today);

            if ($amount === null) {
                $unconverted[$currency] = $currency;

                continue;
            }

            $sums[$estimate->isIncome() ? 'income' : 'expenses'] += $amount;
        }

        return [
            'totals' => [...$sums, 'net' => $sums['income'] - $sums['expenses']],
            'unconverted' => array_values($unconverted),
        ];
    }
}
