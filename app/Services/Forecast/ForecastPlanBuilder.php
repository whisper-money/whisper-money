<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastItem;
use App\Models\User;
use App\Services\BalanceLookup;
use App\Services\Forecast\Data\BalanceTrend;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use App\Services\Forecast\Data\ProjectionInput;
use App\Services\Forecast\Data\TransferEstimate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gathers what a user's forecast in one space is projected from: the
 * accounts it includes (every live account unless the user left it out),
 * each from its last known balance with its yearly rate and wanted minimum;
 * the occurrences of the lines that still project for them (the ones the
 * user made or kept by hand: detected recurring lines stay stored but no
 * longer project, see ForecastItem::projecting()); the balance trend of
 * each account that only has a balance history (see ForecastBalanceTrends);
 * the income and spend estimates of each parent category; and the usual
 * transfers between the user's accounts (see TransferEstimator), which move
 * money as transfer occurrences. Estimates are worked out over every
 * included account, because a budget is split across them, and only then
 * narrowed to the selection. A transfer counts when either of its accounts
 * is included and selected; the projector moves only the accounts it
 * projects.
 *
 * The forecast page, jobs and MCP tool of the later phases call it; until
 * then only the tests do.
 *
 * @api
 */
class ForecastPlanBuilder
{
    public function __construct(
        private readonly OccurrenceExpander $expander,
        private readonly EstimateCalculator $estimates,
        private readonly ForecastAccounts $accounts,
        private readonly TransferEstimator $transfers,
        private readonly ForecastBalanceTrends $trends,
    ) {}

    /**
     * @param  list<string>|null  $accountIds  Null projects every included account.
     * @param  string|null  $startDate  `Y-m-d`; the user's today when null.
     */
    public function build(User $user, string $spaceId, ?array $accountIds = null, int $horizonMonths = 12, ?string $startDate = null): ForecastPlan
    {
        $startDate ??= $user->localToday()->toDateString();
        $settings = $this->accounts->settings($user, $spaceId);
        $included = $this->accounts->included($user, $spaceId, $settings);
        $items = $this->items($user, $spaceId, $included->modelKeys());
        $occurrences = $this->expander->expand($items->map(ExpandableItem::fromModel(...)), $startDate, ForecastCalendar::horizonEnd($startDate, $horizonMonths));
        $categoryEstimates = $this->estimates->estimate($user, $spaceId, $included, $startDate, $horizonMonths, $occurrences, $items->pluck('category_id', 'id')->all());
        $selected = $accountIds === null ? $included : $included->filter(fn (Account $account): bool => in_array($account->id, $accountIds, true))->values();
        $selectedIds = array_flip($selected->modelKeys());
        $trends = $this->trends->for($selected, $startDate);

        $selectedEstimates = array_values(array_filter($categoryEstimates, fn (CategoryEstimate $estimate): bool => isset($selectedIds[$estimate->accountId])));
        $selectedTransfers = array_values(array_filter(
            $this->transfers->estimate($user, $spaceId, $included, $startDate, $horizonMonths),
            fn (TransferEstimate $estimate): bool => isset($selectedIds[$estimate->fromAccountId]) || isset($selectedIds[$estimate->toAccountId]),
        ));
        $touchesSelection = fn (Occurrence $occurrence): bool => isset($selectedIds[$occurrence->accountId])
            || ($occurrence->destinationAccountId !== null && isset($selectedIds[$occurrence->destinationAccountId]));

        return new ForecastPlan(
            new ProjectionInput(
                $this->projectionAccounts($selected, $settings, $startDate, $trends),
                [...array_values(array_filter($occurrences, $touchesSelection)), ...$this->transferOccurrences($selectedTransfers)],
                array_map(fn (CategoryEstimate $estimate) => $estimate->toMonthlyEstimate(), $selectedEstimates),
                $startDate,
                $horizonMonths,
            ),
            $selectedEstimates,
            $items,
            $selectedTransfers,
            $trends,
        );
    }

    /**
     * @param  list<TransferEstimate>  $estimates
     * @return list<Occurrence>
     */
    private function transferOccurrences(array $estimates): array
    {
        $occurrences = [];

        foreach ($estimates as $estimate) {
            $occurrence = $estimate->toOccurrence();

            if ($occurrence !== null) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * @param  list<string>  $accountIds
     * @return Collection<int, ForecastItem>
     */
    private function items(User $user, string $spaceId, array $accountIds): Collection
    {
        return ForecastItem::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->projecting()
            ->where(fn ($query) => $query->whereIn('account_id', $accountIds)->orWhereIn('destination_account_id', $accountIds))
            ->with(['versions', 'overrides'])
            ->get();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<string, ForecastAccountSetting>  $settings
     * @param  array<string, BalanceTrend>  $trends
     * @return list<ProjectionAccount>
     */
    private function projectionAccounts(Collection $accounts, Collection $settings, string $startDate, array $trends): array
    {
        $day = Carbon::parse($startDate);
        $balances = BalanceLookup::forAccounts($accounts->modelKeys(), $day, $day);

        return $accounts
            ->map(fn (Account $account): ProjectionAccount => new ProjectionAccount(
                $account->id,
                $balances->getBalanceAt($account->id, $day),
                $settings->get($account->id)?->annual_rate_bps,
                $settings->get($account->id)?->minimum_balance,
                ($trends[$account->id] ?? null)?->monthlySlope,
                ($trends[$account->id] ?? null)?->band,
            ))
            ->values()
            ->all();
    }
}
