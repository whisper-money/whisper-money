<?php

namespace App\Services\Forecast;

use App\Enums\AccountType;
use App\Enums\ForecastFrequency;
use App\Models\Account;
use App\Models\User;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The forecast of one account as its page shows it: the warnings, the four
 * figures, the chart and the plan, plus the projection input the page can
 * recompute from. Every account the forecast includes is projected once, so
 * each one's tab can say whether it has a problem.
 *
 * The month in progress is always shown, followed by the horizon. Without
 * Pro only the next month is: the months after it are listed by name, with
 * nothing in them but how many of them hold a problem, and the projection
 * input stops at the end of the next month.
 */
class ForecastPresenter
{
    /**
     * Months of real history the chart shows before today's.
     */
    private const PAST_MONTHS = 2;

    /**
     * Future months a user without Pro sees.
     */
    private const FREE_MONTHS = 1;

    public function __construct(
        private readonly ForecastPlanBuilder $builder,
        private readonly ForecastProjector $projector,
        private readonly ForecastAccounts $accounts,
        private readonly ForecastAlertEvaluator $alerts,
        private readonly ForecastPastMonths $pastMonths,
        private readonly ForecastPlanRows $rows,
    ) {}

    /**
     * @param  string|null  $accountId  Null picks the account with a problem, else the main checking account.
     * @return array{forecast: array<string, mixed>, projectionInput: array<string, mixed>|null}
     *
     * @throws NotFoundHttpException When the account is not one the forecast includes.
     */
    public function present(User $user, string $spaceId, ?string $accountId, int $horizon): array
    {
        $today = $user->localToday()->toDateString();
        $isPro = $user->hasProPlan();
        $included = $this->accounts->included($user, $spaceId);
        $base = ['today' => $today, 'horizon' => $horizon, 'isPro' => $isPro];

        if ($included->isEmpty()) {
            abort_if($accountId !== null, 404);

            return ['forecast' => [...$base, 'account' => null, 'accounts' => []], 'projectionInput' => null];
        }

        $plan = $this->builder->build($user, $spaceId, null, $horizon + 1, $today);
        $projected = collect($this->projector->project($plan->input)['accounts'])->keyBy('accountId');
        $visibleCount = 1 + ($isPro ? $horizon : min(self::FREE_MONTHS, $horizon));
        $alerts = $projected->map(fn (array $projection): array => $this->alerts->evaluate(array_slice($projection['months'], 0, $visibleCount), $projection['minimumBalance']));
        $account = $this->selectedAccount($included, $accountId, $alerts->all());
        $projection = $projected[$account->id];
        $visible = array_slice($projection['months'], 0, $visibleCount);
        $locked = array_slice($projection['months'], $visibleCount);
        $visibleMonths = array_map(fn (array $month): string => (string) $month['month'], $visible);

        return [
            'forecast' => [
                ...$base,
                'currencyCode' => $account->currency_code,
                'account' => $this->accountSummary($account, $projection['minimumBalance']),
                'accounts' => $included->map(fn (Account $other): array => [
                    'id' => $other->id,
                    'name' => $other->name,
                    'hasAlert' => $alerts[$other->id]['level'] === ForecastAlertEvaluator::LEVEL_STRONG,
                ])->all(),
                'months' => [
                    ...array_map(fn (string $month, int $index): array => ['month' => $month, 'status' => $index === 0 ? 'current' : 'future'], $visibleMonths, array_keys($visibleMonths)),
                    ...array_map(fn (array $month): array => ['month' => $month['month'], 'status' => 'locked'], $locked),
                ],
                'projection' => $visible,
                'past' => $this->pastMonths->forAccount($user, $spaceId, $account, $today, self::PAST_MONTHS),
                'alert' => [
                    ...$alerts[$account->id],
                    'causes' => $this->causes($plan, $account->id, $alerts[$account->id]['month']),
                ],
                'lockedAlertCount' => $isPro ? null : $this->alerts->strongCount($locked, $projection['minimumBalance']),
                'kpis' => $this->kpis($visible, $this->openingBalance($plan, $account->id)),
                'plan' => $this->rows->build($user, $plan, $account, $visibleMonths),
            ],
            'projectionInput' => $this->projectionInput($plan, $account->id, $today, $visibleCount),
        ];
    }

    /**
     * @param  Collection<int, Account>  $included
     * @param  array<string, array{level: string}>  $alerts
     */
    private function selectedAccount(Collection $included, ?string $accountId, array $alerts): Account
    {
        if ($accountId !== null) {
            return $included->firstWhere('id', $accountId) ?? abort(404);
        }

        return $included->first(fn (Account $account): bool => $alerts[$account->id]['level'] === ForecastAlertEvaluator::LEVEL_STRONG)
            ?? $included->sortBy('position')->first(fn (Account $account): bool => $account->type === AccountType::Checking)
            ?? $included->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountSummary(Account $account, ?int $minimumBalance): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'currencyCode' => $account->currency_code,
            'minimumBalance' => $minimumBalance,
        ];
    }

    /**
     * The four figures over the months shown: today's balance, the last
     * month's close and its range, the lowest point, and how much of the
     * spend is concrete rather than estimated.
     *
     * @param  list<array<string, int|string|null>>  $months
     * @return array<string, mixed>
     */
    private function kpis(array $months, int $balanceToday): array
    {
        $last = end($months);
        $lowest = collect($months)->sortBy('min')->first();
        $expenses = array_sum(array_column($months, 'expenses'));
        $estimated = array_sum(array_column($months, 'estimated'));

        return [
            'balanceToday' => $balanceToday,
            'end' => ['month' => $last['month'], 'close' => $last['close'], 'band' => $last['band']],
            'lowest' => ['month' => $lowest['month'], 'min' => $lowest['min'], 'minDate' => $lowest['minDate']],
            'concreteShare' => $expenses > 0 ? (int) round(100 * ($expenses - $estimated) / $expenses) : null,
        ];
    }

    /**
     * The two largest charges of the problem month that are not monthly:
     * what the user already knows is coming, which explains the dip.
     *
     * @return list<array{name: string, amount: int}>
     */
    private function causes(ForecastPlan $plan, string $accountId, ?string $month): array
    {
        if ($month === null) {
            return [];
        }

        $items = $plan->items->keyBy('id');

        return collect($plan->input->occurrences)
            ->filter(fn (Occurrence $occurrence): bool => $occurrence->accountId === $accountId
                && $occurrence->amount < 0
                && ForecastCalendar::monthKey($occurrence->date) === $month
                && $items->get($occurrence->itemId)?->frequency !== ForecastFrequency::Monthly)
            ->sortBy('amount')
            ->take(2)
            ->map(fn (Occurrence $occurrence): array => ['name' => (string) $items->get($occurrence->itemId)?->name, 'amount' => -$occurrence->amount])
            ->values()
            ->all();
    }

    private function projectionAccount(ForecastPlan $plan, string $accountId): ProjectionAccount
    {
        return collect($plan->input->accounts)->first(fn (ProjectionAccount $candidate): bool => $candidate->id === $accountId);
    }

    private function openingBalance(ForecastPlan $plan, string $accountId): int
    {
        return $this->projectionAccount($plan, $accountId)->openingBalance;
    }

    /**
     * The engine's input for the selected account over the months shown, in
     * the camelCase shape resources/js/lib/forecast/project.ts takes.
     *
     * @return array<string, mixed>
     */
    private function projectionInput(ForecastPlan $plan, string $accountId, string $today, int $months): array
    {
        $end = ForecastCalendar::horizonEnd($today, $months);
        $lastMonth = ForecastCalendar::monthKey($end);
        $account = $this->projectionAccount($plan, $accountId);

        return [
            'accounts' => [[
                'id' => $account->id,
                'openingBalance' => $account->openingBalance,
                'annualRateBps' => $account->annualRateBps,
                'minimumBalance' => $account->minimumBalance,
            ]],
            'occurrences' => array_values(array_map(
                fn (Occurrence $occurrence): array => $occurrence->toArray(),
                array_filter($plan->input->occurrences, fn (Occurrence $occurrence): bool => $occurrence->date <= $end
                    && ($occurrence->accountId === $accountId || $occurrence->destinationAccountId === $accountId)),
            )),
            'estimates' => array_values(array_map(
                fn (MonthlyEstimate $estimate): array => ['accountId' => $estimate->accountId, 'month' => $estimate->month, 'amount' => $estimate->amount],
                array_filter($plan->input->estimates, fn (MonthlyEstimate $estimate): bool => $estimate->accountId === $accountId && $estimate->month <= $lastMonth),
            )),
            'startDate' => $today,
            'horizonMonths' => $months,
        ];
    }
}
