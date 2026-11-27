<?php

namespace App\Services\Forecast;

use App\Enums\ForecastAlertLevel;
use App\Enums\ForecastEstimateKind;
use App\Models\Account;
use App\Services\ExchangeRateService;
use App\Services\Forecast\Data\ForecastContext;
use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use App\Services\Forecast\Data\ProjectionInput;

/**
 * The "All accounts" view of the forecast page: every included account
 * together, in the user's currency.
 *
 * The total is the projection of the plan converted at today's rate, so a
 * transfer between two included accounts leaves it untouched. Each account
 * keeps its own projection, in its own currency, which its closing row and
 * its warnings read (an account's money cannot cover another's shortfall
 * without a transfer). The plan sums the parent categories across accounts
 * (the totals converted, each line and estimated rest in its account's
 * currency), then lists the estimated growth, the transfers and the close of
 * each account.
 *
 * An account whose currency has no rate today is left out of the total
 * (and listed in `unconvertedCurrencies`), rather than added unconverted.
 *
 * The warning reads the accounts: when one runs short but what the others
 * have above their own threshold covers it, the problem is where the money
 * is ("split", with the transfer that fixes it when one account can lend it
 * all); when they cannot, the total itself does not hold ("short").
 */
class ForecastAllAccountsPresenter
{
    private const LEVEL_SPLIT = 'split';

    private const LEVEL_SHORT = 'short';

    private const LEVEL_OK = 'ok';

    public function __construct(
        private readonly ForecastProjector $projector,
        private readonly ForecastAlertEvaluator $alerts,
        private readonly ForecastPastMonths $pastMonths,
        private readonly ForecastPlanRows $rows,
        private readonly ForecastTransferSuggester $transfers,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ForecastContext $context): array
    {
        $unconverted = $context->included->pluck('currency_code')->unique()
            ->filter(fn (string $currency): bool => $this->rates->convertOrNull($currency, $context->user->currency_code, 1, $context->today) === null)
            ->values()
            ->all();
        $currencies = $context->included
            ->reject(fn (Account $account): bool => in_array($account->currency_code, $unconverted, true))
            ->pluck('currency_code', 'id')
            ->all();
        $converted = $this->converted($context->plan->input, $currencies, $context);
        $total = array_slice($this->projector->project($converted)['total'], 0, $context->visibleCount);
        $months = array_column($total, 'month');

        return [
            'projection' => $total,
            'unconvertedCurrencies' => $unconverted,
            'past' => $this->past($context, $currencies),
            'accountForecasts' => $context->included->map(fn (Account $account): array => [
                ...$context->accountSummary($account),
                'projection' => $context->visibleMonths($account->id),
            ])->all(),
            'alert' => $this->alert($context, $currencies),
            'lockedAlertCount' => $context->user->hasProPlan() ? null : $this->lockedAlertCount($context),
            'kpis' => [
                'balanceToday' => array_sum(array_map(fn (ProjectionAccount $account): int => $account->openingBalance, $converted->accounts)),
                'accountCount' => count($converted->accounts),
                'end' => ['month' => end($total)['month'], 'close' => end($total)['close'], 'band' => end($total)['band']],
                'saved' => array_sum(array_column($total, 'income')) - array_sum(array_column($total, 'expenses')),
                'growth' => array_sum(array_column($total, 'growth')),
                'moved' => $this->moved($converted, $context, ForecastCalendar::horizonEnd($context->today, $context->visibleCount)),
            ],
            'plan' => [
                ...$this->plan($context, $currencies, $months),
                'growth' => array_column($total, 'growth', 'month'),
                'transferEffect' => array_column($total, 'transfers', 'month'),
            ],
        ];
    }

    /**
     * An amount of an account's currency in the user's, at today's rate.
     */
    private function toUserCurrency(ForecastContext $context, string $currency, int $amount): int
    {
        return $this->rates->convert($currency, $context->user->currency_code, $amount, $context->today);
    }

    /**
     * The projection input of the accounts counted, with every amount in
     * the user's currency. A transfer is converted at its source's rate on
     * both sides, so it stays neutral in the total.
     *
     * @param  array<string, string>  $currencies  The accounts counted: their currency by id.
     */
    private function converted(ProjectionInput $input, array $currencies, ForecastContext $context): ProjectionInput
    {
        $convert = fn (?string $accountId, int $amount, ?string $fallbackId = null): int => $this->toUserCurrency($context, $currencies[$accountId] ?? $currencies[$fallbackId] ?? $context->user->currency_code, $amount);
        $counted = fn (?string $accountId): bool => $accountId !== null && isset($currencies[$accountId]);

        return new ProjectionInput(
            array_map(fn (ProjectionAccount $account): ProjectionAccount => new ProjectionAccount(
                $account->id,
                $convert($account->id, $account->openingBalance),
                $account->annualRateBps,
                $account->minimumBalance === null ? null : $convert($account->id, $account->minimumBalance),
            ), array_values(array_filter($input->accounts, fn (ProjectionAccount $account): bool => $counted($account->id)))),
            array_map(fn (Occurrence $occurrence): Occurrence => new Occurrence(
                $occurrence->itemId,
                $occurrence->accountId,
                $occurrence->destinationAccountId,
                $occurrence->date,
                $occurrence->occurrenceDate,
                $convert($occurrence->accountId, $occurrence->amount, $occurrence->destinationAccountId),
            ), array_values(array_filter($input->occurrences, fn (Occurrence $occurrence): bool => $counted($occurrence->accountId) || $counted($occurrence->destinationAccountId)))),
            array_map(fn (MonthlyEstimate $estimate): MonthlyEstimate => $estimate->withAmount($convert($estimate->accountId, $estimate->amount)), array_values(array_filter($input->estimates, fn (MonthlyEstimate $estimate): bool => $counted($estimate->accountId)))),
            $input->startDate,
            $input->horizonMonths,
        );
    }

    /**
     * The real months before today's, every account counted added up in the
     * user's currency, at today's rate like the rest of the view. A month's
     * close is null unless every account's history reaches it, so an account
     * synced late does not draw a step in the line; a total's lowest point is
     * not the sum of the accounts' lowest points, so it is left out.
     *
     * @param  array<string, string>  $currencies  The accounts counted: their currency by id.
     * @return list<array{month: string, income: int, expenses: int, close: int|null, min: null}>
     */
    private function past(ForecastContext $context, array $currencies): array
    {
        $counted = $context->included->filter(fn (Account $account): bool => isset($currencies[$account->id]))->values();
        $byAccount = $this->pastMonths->forAccounts($context->user, $context->spaceId, $counted, $context->today, ForecastPresenter::PAST_MONTHS);
        $months = [];

        foreach ($byAccount as $accountId => $accountMonths) {
            $convert = fn (int $amount): int => $this->toUserCurrency($context, $currencies[$accountId], $amount);

            foreach ($accountMonths as $month) {
                $sum = $months[$month['month']] ?? ['month' => $month['month'], 'income' => 0, 'expenses' => 0, 'close' => 0, 'min' => null];
                $sum['income'] += $convert($month['income']);
                $sum['expenses'] += $convert($month['expenses']);
                $sum['close'] = $month['close'] === null || $sum['close'] === null ? null : $sum['close'] + $convert($month['close']);
                $months[$month['month']] = $sum;
            }
        }

        return array_values($months);
    }

    /**
     * The account in the worst trouble, and whether the others can cover it.
     *
     * @param  array<string, string>  $currencies  The accounts counted: their currency by id.
     * @return array{level: string, accountId: string|null, month: string|null, min: int|null, minDate: string|null, softMonths: list<string>, transfer: array<string, mixed>|null}
     */
    private function alert(ForecastContext $context, array $currencies): array
    {
        $strong = $context->included
            ->filter(fn (Account $account): bool => $context->alerts[$account->id]['level'] === ForecastAlertLevel::Strong->value)
            ->sortBy(fn (Account $account): int => $this->toUserCurrency($context, $account->currency_code, (int) $context->alerts[$account->id]['min']))
            ->first();

        if ($strong === null) {
            $softMonths = collect($context->alerts)->pluck('softMonths')->flatten()->unique()->sort()->values()->all();

            return ['level' => $softMonths === [] ? self::LEVEL_OK : ForecastAlertLevel::Soft->value, 'accountId' => null, 'month' => null, 'min' => null, 'minDate' => null, 'softMonths' => $softMonths, 'transfer' => null];
        }

        $alert = $context->alerts[$strong->id];
        $covered = $this->othersCover($context, $currencies);

        return [
            'level' => $covered ? self::LEVEL_SPLIT : self::LEVEL_SHORT,
            'accountId' => $strong->id,
            'month' => $alert['month'],
            'min' => $alert['min'],
            'minDate' => $alert['minDate'],
            'softMonths' => [],
            'transfer' => $covered ? $this->transfers->suggest($strong, $context->visibleMonths($strong->id), $context->included, $context->projected, $context->thresholds, $context->today) : null,
        ];
    }

    /**
     * Whether, in every month shown, what the accounts have above their
     * threshold covers what the others are missing below theirs. Only a
     * liquid account has a threshold: a card, a loan, an investment or a
     * house neither lends nor counts as short.
     *
     * @param  array<string, string>  $currencies  The accounts counted: their currency by id.
     */
    private function othersCover(ForecastContext $context, array $currencies): bool
    {
        for ($index = 0; $index < $context->visibleCount; $index++) {
            $room = 0;

            foreach ($context->included as $account) {
                $threshold = $context->thresholds[$account->id];

                if ($threshold !== null && isset($currencies[$account->id])) {
                    $room += $this->toUserCurrency($context, $account->currency_code, (int) $context->visibleMonths($account->id)[$index]['min'] - $threshold);
                }
            }

            if ($room < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many of the months the free plan hides hold a problem on any account.
     */
    private function lockedAlertCount(ForecastContext $context): int
    {
        $months = [];

        foreach ($context->included as $account) {
            foreach ($context->lockedMonths($account->id) as $month) {
                if ($this->alerts->levelOf($month, $context->thresholds[$account->id]) === ForecastAlertLevel::Strong) {
                    $months[(string) $month['month']] = true;
                }
            }
        }

        return count($months);
    }

    /**
     * What the transfers between two included accounts move over the months
     * shown, in the user's currency: money that changes place, not the total.
     */
    private function moved(ProjectionInput $converted, ForecastContext $context, string $until): int
    {
        $included = array_flip($context->included->modelKeys());
        $moved = 0;

        foreach ($converted->occurrences as $occurrence) {
            if ($occurrence->date <= $until && isset($included[$occurrence->accountId], $included[(string) $occurrence->destinationAccountId])) {
                $moved += abs($occurrence->amount);
            }
        }

        return $moved;
    }

    /**
     * The plan of every account, each parent category with what it adds up
     * to in the user's currency: `totals` by month and, for the month in
     * progress, what was really paid and whether anything is still to come.
     *
     * @param  array<string, string>  $currencies
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    private function plan(ForecastContext $context, array $currencies, array $months): array
    {
        $plan = $this->rows->buildAll($context->user, $context->plan, $months);
        $withTotals = fn (array $group): array => [...$group, ...$this->groupTotals($context, $currencies, $group)];

        return [
            'income' => array_map($withTotals, $plan['income']),
            'expenses' => array_map($withTotals, $plan['expenses']),
            'transfers' => $plan['transfers'],
        ];
    }

    /**
     * @param  array<string, string>  $currencies
     * @param  array<string, mixed>  $group
     * @return array{totals: array<string, int>, current: array{real: int, pending: bool}}
     */
    private function groupTotals(ForecastContext $context, array $currencies, array $group): array
    {
        $totals = [];
        $real = 0;
        $pending = false;
        $add = function (string $accountId, array $amounts) use ($context, $currencies, &$totals): void {
            if (! isset($currencies[$accountId])) {
                return;
            }

            foreach ($amounts as $month => $amount) {
                $totals[$month] = ($totals[$month] ?? 0) + $this->toUserCurrency($context, $currencies[$accountId], $amount);
            }
        };

        foreach ($group['items'] as $line) {
            $add($line['accountId'], $line['amounts']);
            $real += isset($currencies[$line['accountId']]) ? $this->toUserCurrency($context, $currencies[$line['accountId']], $line['current']['real'] ?? 0) : 0;
            $pending = $pending || $line['current']['status'] === 'pending';
        }

        foreach ($group['estimates'] as $estimate) {
            $add($estimate['accountId'], $estimate['amounts']);
            $sign = $estimate['kind'] === ForecastEstimateKind::Income->value ? 1 : -1;
            $real += isset($currencies[$estimate['accountId']]) ? $sign * $this->toUserCurrency($context, $currencies[$estimate['accountId']], $estimate['current']['spent'] ?? 0) : 0;
        }

        return ['totals' => $totals, 'current' => ['real' => $real, 'pending' => $pending]];
    }
}
