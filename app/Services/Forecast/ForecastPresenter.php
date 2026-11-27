<?php

namespace App\Services\Forecast;

use App\Enums\AccountType;
use App\Enums\ForecastAlertLevel;
use App\Models\Account;
use App\Models\User;
use App\Services\Forecast\Data\ForecastContext;
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
 * each one's tab can say whether it has a problem. With ALL_ACCOUNTS the
 * page shows them together instead (see ForecastAllAccountsPresenter).
 *
 * The month in progress is always shown, followed by the horizon. Without
 * Pro only the next month is: the months after it are listed by name, with
 * nothing in them but how many of them hold a problem, and the projection
 * input stops at the end of the next month.
 */
class ForecastPresenter
{
    /**
     * The `account` query value of the view of every account together.
     */
    public const ALL_ACCOUNTS = 'all';

    /**
     * Months of real history the chart shows before today's.
     */
    public const PAST_MONTHS = 2;

    public function __construct(
        private readonly ForecastPlanBuilder $builder,
        private readonly ForecastProjector $projector,
        private readonly ForecastAccounts $accounts,
        private readonly ForecastAlertEvaluator $alerts,
        private readonly ForecastPastMonths $pastMonths,
        private readonly ForecastPlanRows $rows,
        private readonly ForecastTransferSuggester $transfers,
        private readonly ForecastAllAccountsPresenter $allAccounts,
        private readonly ForecastAlertCauses $causes,
    ) {}

    /**
     * @param  string|null  $accountId  Null picks the account with a problem, else the main checking account, else one that is not projected only; ALL_ACCOUNTS shows them all together.
     * @return array{forecast: array<string, mixed>, projectionInput: array<string, mixed>|null}
     *
     * @throws NotFoundHttpException When the account is not one the forecast includes.
     */
    public function present(User $user, string $spaceId, ?string $accountId, int $horizon): array
    {
        $today = $user->localToday()->toDateString();
        $isPro = $user->hasProPlan();
        $included = $this->accounts->included($user, $spaceId);
        $all = $accountId === self::ALL_ACCOUNTS;
        $base = [
            'scope' => $all ? self::ALL_ACCOUNTS : 'account',
            'today' => $today,
            'horizon' => $horizon,
            'isPro' => $isPro,
            'accountChoices' => $this->accounts->choices($user, $spaceId),
        ];

        if ($included->isEmpty()) {
            abort_if($accountId !== null && ! $all, 404);

            return ['forecast' => [...$base, 'account' => null, 'accounts' => []], 'projectionInput' => null];
        }

        $context = $this->context($user, $spaceId, $today, $included, $horizon, $isPro);

        if ($all) {
            return [
                'forecast' => [
                    ...$base,
                    'currencyCode' => $user->currency_code,
                    'account' => null,
                    'accounts' => $this->tabs($context),
                    'months' => $this->monthColumns($context, $included->first()->id),
                    ...$this->allAccounts->present($context),
                ],
                'projectionInput' => $this->projectionInput($context->plan, null, $today, $context->visibleCount),
            ];
        }

        $account = $this->selectedAccount($included, $accountId, $context->alerts);
        $visible = $context->visibleMonths($account->id);
        $alert = $context->alerts[$account->id];

        return [
            'forecast' => [
                ...$base,
                'currencyCode' => $account->currency_code,
                'account' => $context->accountSummary($account),
                'accounts' => $this->tabs($context),
                'months' => $this->monthColumns($context, $account->id),
                'projection' => $visible,
                'past' => $this->pastMonths->forAccount($user, $spaceId, $account, $today, self::PAST_MONTHS),
                'alert' => [
                    ...$alert,
                    'causes' => $this->causes->for($context->plan, $account->id, $alert['month']),
                    'transfer' => $this->transfers->suggest($account, $visible, $included, $context->projected, $context->thresholds, $today),
                ],
                'lockedAlertCount' => $isPro ? null : $this->alerts->strongCount($context->lockedMonths($account->id), $context->thresholds[$account->id]),
                'kpis' => $this->kpis($visible, $context->projectionAccount($account->id)->openingBalance),
                'plan' => $this->rows->build($user, $context->plan, $account, array_column($visible, 'month')),
            ],
            'projectionInput' => $this->projectionInput($context->plan, $account->id, $today, $context->visibleCount),
        ];
    }

    /**
     * The forecast for readers without the page (the MCP get_forecast tool):
     * each included account, or the one asked for, over the months the user
     * sees, in its own currency, with each month's problem (`strong`, `soft`
     * or `ok`) and, for each problem month, the charges behind it. Without
     * an account, also the "All accounts" view's total in the user's
     * currency and its warning. Without Pro the months stop at the next one,
     * exactly as on the page; how many of the months after it hold a problem
     * is all that is said about them.
     *
     * @return array{today: string, horizon: int, isPro: bool, accounts: list<array<string, mixed>>, all: array<string, mixed>|null}|null Null when the account is not one the forecast includes.
     */
    public function overview(User $user, string $spaceId, ?string $accountId, int $horizon): ?array
    {
        $today = $user->localToday()->toDateString();
        $isPro = $user->hasProPlan();
        $included = $this->accounts->included($user, $spaceId);
        $base = ['today' => $today, 'horizon' => $horizon, 'isPro' => $isPro];
        $shown = $accountId === null ? $included : $included->where('id', $accountId)->values();

        if ($shown->isEmpty()) {
            return $accountId === null ? [...$base, 'accounts' => [], 'all' => null] : null;
        }

        $context = $this->context($user, $spaceId, $today, $included, $horizon, $isPro);

        return [
            ...$base,
            'accounts' => $shown->map(fn (Account $account): array => $this->accountOverview($context, $account))->all(),
            'all' => $accountId === null ? $this->allAccountsOverview($context) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountOverview(ForecastContext $context, Account $account): array
    {
        $months = $context->visibleMonths($account->id);
        $threshold = $context->thresholds[$account->id];
        $levels = collect($months)->mapWithKeys(fn (array $month): array => [(string) $month['month'] => $this->alerts->levelOf($month, $threshold)->value ?? 'ok']);

        return [
            'account' => $account,
            'threshold' => $threshold,
            'months' => $months,
            ...$this->endAndLowest($months),
            'levels' => $levels->all(),
            'alert' => $context->alerts[$account->id],
            'causes' => $levels->reject(fn (string $level): bool => $level === 'ok')->map(fn (string $level, string $month): array => $this->causes->for($context->plan, $account->id, $month))->all(),
            'lockedAlertCount' => $context->user->hasProPlan() ? null : $this->alerts->strongCount($context->lockedMonths($account->id), $threshold),
        ];
    }

    /**
     * What the "All accounts" view shows besides each account: the total
     * projected in the user's currency and its warning.
     *
     * @return array<string, mixed>
     */
    private function allAccountsOverview(ForecastContext $context): array
    {
        $all = $this->allAccounts->present($context);

        return [
            'currencyCode' => $context->user->currency_code,
            'months' => $all['projection'],
            ...$this->endAndLowest($all['projection']),
            'balanceToday' => $all['kpis']['balanceToday'],
            'unconvertedCurrencies' => $all['unconvertedCurrencies'],
            'alert' => $all['alert'],
            'lockedAlertCount' => $all['lockedAlertCount'],
        ];
    }

    /**
     * Every included account projected once in its own currency, with its
     * warnings over the months shown: the month in progress and the horizon,
     * or without Pro only the next month.
     *
     * @param  Collection<int, Account>  $included
     */
    private function context(User $user, string $spaceId, string $today, Collection $included, int $horizon, bool $isPro): ForecastContext
    {
        $plan = $this->builder->build($user, $spaceId, null, $horizon + 1, $today);
        $projected = collect($this->projector->project($plan->input)['accounts'])->keyBy('accountId')->all();
        $visibleCount = 1 + ($isPro ? $horizon : min(ForecastEditWindow::FREE_MONTHS, $horizon));
        $thresholds = $included->mapWithKeys(fn (Account $account): array => [$account->id => $this->alerts->threshold($account->type, $projected[$account->id]['minimumBalance'])])->all();
        $alerts = [];

        foreach ($projected as $id => $projection) {
            $alerts[$id] = $this->alerts->evaluate(array_slice($projection['months'], 0, $visibleCount), $thresholds[$id]);
        }

        return new ForecastContext($user, $spaceId, $today, $included, $plan, $projected, $visibleCount, $thresholds, $alerts);
    }

    /**
     * One pill per account, marked when it has a problem or is projected
     * only (it never warns).
     *
     * @return list<array<string, mixed>>
     */
    private function tabs(ForecastContext $context): array
    {
        return $context->included->map(fn (Account $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'currencyCode' => $account->currency_code,
            'projectionOnly' => ForecastAccounts::isProjectionOnly($account->type),
            'hasAlert' => $this->isStrong($context->alerts[$account->id]),
        ])->all();
    }

    /**
     * The plan's columns: the month in progress, the months shown and, without
     * Pro, the locked ones by name only.
     *
     * @return list<array{month: string, status: 'current'|'future'|'locked'}>
     */
    private function monthColumns(ForecastContext $context, string $accountId): array
    {
        $columns = [];

        foreach ($context->visibleMonths($accountId) as $index => $month) {
            $columns[] = ['month' => (string) $month['month'], 'status' => $index === 0 ? 'current' : 'future'];
        }

        foreach ($context->lockedMonths($accountId) as $month) {
            $columns[] = ['month' => (string) $month['month'], 'status' => 'locked'];
        }

        return $columns;
    }

    /**
     * @param  array{level: string}  $alert
     */
    private function isStrong(array $alert): bool
    {
        return $alert['level'] === ForecastAlertLevel::Strong->value;
    }

    /**
     * @param  Collection<int, Account>  $included
     * @param  array<string, array{level: string, min: int|null}>  $alerts
     */
    private function selectedAccount(Collection $included, ?string $accountId, array $alerts): Account
    {
        if ($accountId !== null) {
            return $included->firstWhere('id', $accountId) ?? abort(404);
        }

        return $included->filter(fn (Account $account): bool => $this->isStrong($alerts[$account->id]))->sortBy(fn (Account $account): int => (int) $alerts[$account->id]['min'])->first()
            ?? $included->sortBy('position')->first(fn (Account $account): bool => $account->type === AccountType::Checking)
            ?? $included->first(fn (Account $account): bool => ! ForecastAccounts::isProjectionOnly($account->type))
            ?? $included->first();
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
        $expenses = array_sum(array_column($months, 'expenses'));
        $estimated = array_sum(array_column($months, 'estimated'));

        return [
            'balanceToday' => $balanceToday,
            ...$this->endAndLowest($months),
            'concreteShare' => $expenses > 0 ? (int) round(100 * ($expenses - $estimated) / $expenses) : null,
        ];
    }

    /**
     * The last month's close and its range, and the lowest point.
     *
     * @param  list<array<string, int|string|null>>  $months
     * @return array{end: array{month: int|string|null, close: int|string|null, band: int|string|null}, lowest: array{month: int|string|null, min: int|string|null, minDate: int|string|null}}
     */
    private function endAndLowest(array $months): array
    {
        $last = end($months);
        $lowest = collect($months)->sortBy('min')->first();

        return [
            'end' => ['month' => $last['month'], 'close' => $last['close'], 'band' => $last['band']],
            'lowest' => ['month' => $lowest['month'], 'min' => $lowest['min'], 'minDate' => $lowest['minDate']],
        ];
    }

    /**
     * The engine's input over the months shown, in the camelCase shape
     * resources/js/lib/forecast/project.ts takes. Every included account is
     * in it, the selected one first, so the page can show what a transfer
     * does to both sides; an account's projection never depends on another's.
     *
     * @param  string|null  $accountId  The account shown, or null for all of them.
     * @return array<string, mixed>
     */
    private function projectionInput(ForecastPlan $plan, ?string $accountId, string $today, int $months): array
    {
        $end = ForecastCalendar::horizonEnd($today, $months);
        $lastMonth = ForecastCalendar::monthKey($end);
        $accounts = collect($plan->input->accounts)->sortBy(fn (ProjectionAccount $account): int => $account->id === $accountId ? 0 : 1)->values();

        return [
            'accounts' => $accounts->map(fn (ProjectionAccount $account): array => [
                'id' => $account->id,
                'openingBalance' => $account->openingBalance,
                'annualRateBps' => $account->annualRateBps,
                'minimumBalance' => $account->minimumBalance,
            ])->all(),
            'occurrences' => array_values(array_map(
                fn (Occurrence $occurrence): array => $occurrence->toArray(),
                array_filter($plan->input->occurrences, fn (Occurrence $occurrence): bool => $occurrence->date <= $end),
            )),
            'estimates' => array_values(array_map(
                fn (MonthlyEstimate $estimate): array => $estimate->toArray(),
                array_filter($plan->input->estimates, fn (MonthlyEstimate $estimate): bool => $estimate->month <= $lastMonth),
            )),
            'startDate' => $today,
            'horizonMonths' => $months,
        ];
    }
}
