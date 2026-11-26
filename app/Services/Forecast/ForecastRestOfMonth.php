<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\Category;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionInput;

/**
 * What the forecast still expects for the rest of the month in progress, as
 * Cashflow's "rest of the month" card shows it: the lines not paid yet, the
 * estimated rest of each parent category, and the balance the month closes
 * on. It covers the active space's accounts the forecast includes that hold
 * day-to-day money (a ledger and a balance: checking, savings, others) in the
 * user's currency, so the figures add up without a conversion. A transfer
 * between two of them moves nothing.
 *
 * Occurrences a transaction already paid are left out of both the lines and
 * the close: today's balance already holds them.
 */
class ForecastRestOfMonth
{
    public function __construct(
        private readonly ForecastPlanBuilder $builder,
        private readonly ForecastProjector $projector,
        private readonly ForecastAccounts $accounts,
    ) {}

    /**
     * @return array{month: string, currencyCode: string, lines: list<array{name: string, date: string, amount: int}>, estimates: list<array{name: string|null, amount: int}>, endBalance: int}|null
     *                                                                                                                                                                                               Null without a forecast line or an account to show.
     */
    public function for(User $user): ?array
    {
        $spaceId = $user->activeSpace()->id;

        if (! ForecastItem::query()->where('user_id', $user->id)->forSpace($spaceId)->notArchived()->exists()) {
            return null;
        }

        $accountIds = $this->accountIds($user, $spaceId);

        if ($accountIds === []) {
            return null;
        }

        $today = $user->localToday()->toDateString();
        $plan = $this->builder->build($user, $spaceId, $accountIds, 1, $today);
        $pending = $this->pending($plan, ForecastCalendar::monthKey($today));
        $selected = array_flip($accountIds);

        return [
            'month' => ForecastCalendar::monthKey($today),
            'currencyCode' => (string) $user->currency_code,
            'lines' => $this->lines($plan, $pending, $selected),
            'estimates' => $this->estimates($user, $plan->categoryEstimates),
            'endBalance' => $this->endBalance($plan, $pending),
        ];
    }

    /**
     * @return list<string>
     */
    private function accountIds(User $user, string $spaceId): array
    {
        return $this->accounts->included($user, $spaceId)
            ->filter(fn (Account $account): bool => $account->type->hasTransactionLedger()
                && $account->type->hasBalance()
                && strcasecmp($account->currency_code, (string) $user->currency_code) === 0)
            ->modelKeys();
    }

    /**
     * The month's occurrences no transaction paid yet.
     *
     * @return list<Occurrence>
     */
    private function pending(ForecastPlan $plan, string $month): array
    {
        $paid = ForecastMatch::query()
            ->whereIn('forecast_item_id', $plan->items->modelKeys())
            ->whereBetween('occurrence_date', [ForecastCalendar::monthStart($month.'-01', -1), ForecastCalendar::horizonEnd($month.'-01', 2)])
            ->get(['forecast_item_id', 'occurrence_date'])
            ->mapWithKeys(fn (ForecastMatch $match): array => [$match->forecast_item_id.'|'.$match->occurrence_date->toDateString() => true]);

        return array_values(array_filter(
            $plan->input->occurrences,
            fn (Occurrence $occurrence): bool => ! $paid->has($occurrence->itemId.'|'.$occurrence->occurrenceDate),
        ));
    }

    /**
     * Each pending occurrence by what it does to the accounts shown, soonest
     * first. A transfer between two of them does nothing and is left out.
     *
     * @param  list<Occurrence>  $pending
     * @param  array<string, int>  $selected  Account ids as keys.
     * @return list<array{name: string, date: string, amount: int}>
     */
    private function lines(ForecastPlan $plan, array $pending, array $selected): array
    {
        $names = $plan->items->pluck('name', 'id');
        $lines = [];

        foreach ($pending as $occurrence) {
            $amount = (isset($selected[$occurrence->accountId]) ? $occurrence->amount : 0)
                - ($occurrence->destinationAccountId !== null && isset($selected[$occurrence->destinationAccountId]) ? $occurrence->amount : 0);

            if ($amount !== 0) {
                $lines[] = ['name' => (string) $names->get($occurrence->itemId), 'date' => $occurrence->date, 'amount' => $amount];
            }
        }

        return $lines;
    }

    /**
     * What is left of each parent category's estimate, largest first, as a
     * signed expense. A null name is "Other spending".
     *
     * @param  list<CategoryEstimate>  $estimates
     * @return list<array{name: string|null, amount: int}>
     */
    private function estimates(User $user, array $estimates): array
    {
        $byCategory = [];

        foreach ($estimates as $estimate) {
            $byCategory[$estimate->categoryId ?? ''] = ($byCategory[$estimate->categoryId ?? ''] ?? 0) + $estimate->amount;
        }

        $byCategory = array_filter($byCategory);
        arsort($byCategory);
        $names = Category::query()->where('user_id', $user->id)->whereKey(array_filter(array_keys($byCategory)))->pluck('name', 'id');

        return array_map(
            fn (string $categoryId, int $amount): array => ['name' => $categoryId === '' ? null : $names->get($categoryId), 'amount' => -$amount],
            array_keys($byCategory),
            array_values($byCategory),
        );
    }

    /**
     * @param  list<Occurrence>  $pending
     */
    private function endBalance(ForecastPlan $plan, array $pending): int
    {
        $input = $plan->input;
        $projection = $this->projector->project(new ProjectionInput($input->accounts, $pending, $input->estimates, $input->startDate, $input->horizonMonths));

        return array_sum(array_map(fn (array $account): int => (int) $account['months'][0]['close'], $projection['accounts']));
    }
}
