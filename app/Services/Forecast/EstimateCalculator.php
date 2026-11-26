<?php

namespace App\Services\Forecast;

use App\Enums\ForecastEstimateBasis;
use App\Models\Account;
use App\Models\ForecastEstimateAdjustment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\EstimateRow;
use App\Services\Forecast\Data\Occurrence;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * The estimated rest of each parent category on each account: the spend no
 * concrete line covers, month by month over the horizon.
 *
 * It is the median of the three complete months before the start, over the
 * spend that paid no forecast line, was not marked a one-off and is not a
 * transfer between the user's accounts. Only the months since the account's
 * first transaction count, so a young account is not read as spending
 * nothing. A parent with a budget that fits it (see ForecastBudgetTargets)
 * takes the budget instead, less the concrete lines of that parent in the
 * month, never below zero; the budget covers every account in the user's
 * currency, so each takes the share of it it spent over those months. A
 * parent whose median is under 30 a month in the account's currency is too
 * small for a row: it joins "Other spending" (`categoryId` null) with the
 * uncategorized spend. An adjustment the user made replaces the figure for
 * the months it covers, the latest one winning. In the start month the
 * estimate is what is left of it after what was already spent there.
 */
class EstimateCalculator
{
    private const MEDIAN_MONTHS = 3;

    private const SMALL_ROW_MAJOR_UNITS = 30;

    /**
     * The bucket key of "Other spending", which has no category.
     */
    private const OTHER = '';

    public function __construct(
        private readonly ForecastHistory $history,
        private readonly ForecastBudgetTargets $budgets,
        private readonly TransferPairer $pairer,
    ) {}

    /**
     * @param  Collection<int, Account>  $accounts  Every account of the space the forecast includes.
     * @param  list<Occurrence>  $occurrences  The concrete lines' occurrences over the horizon.
     * @param  array<string, string|null>  $itemCategories  Each line's category, by line id.
     * @return list<CategoryEstimate>
     */
    public function estimate(User $user, string $spaceId, Collection $accounts, string $startDate, int $horizonMonths, array $occurrences, array $itemCategories): array
    {
        $parents = $this->history->parentCategories($user);
        $months = $this->monthKeys($startDate, $horizonMonths);
        $pastMonths = $this->pastMonthsByAccount($user, $accounts, $startDate);
        $spend = $this->spendByBucket($user, $spaceId, $accounts, $startDate, $parents);
        $budgetShares = $this->budgetShares($user, $spaceId, $accounts, $spend, $parents, $pastMonths);
        $concrete = $this->concreteSpendByParent($occurrences, $itemCategories, $parents, $this->inUserCurrency($user, $accounts)->modelKeys());
        $adjustments = $this->adjustments($user, $spaceId, $accounts, $parents);
        $estimates = [];

        foreach ($accounts as $account) {
            $rows = $this->rows($account, $spend[$account->id] ?? [], $budgetShares, $adjustments[$account->id] ?? [], $pastMonths[$account->id], $months[0]);

            foreach ($rows as $bucket => $row) {
                array_push($estimates, ...$this->estimatesFor($account->id, (string) $bucket, $row, $months, $concrete[$bucket] ?? [], $adjustments[$account->id][$bucket] ?? []));
            }
        }

        return $estimates;
    }

    /**
     * The complete months before the start that count for each account: the
     * last three, from the month of its first transaction on.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<string, list<string>>
     */
    private function pastMonthsByAccount(User $user, Collection $accounts, string $startDate): array
    {
        $firstMonths = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('account_id', $accounts->modelKeys())
            ->groupBy('account_id')
            ->selectRaw('account_id, min(transaction_date) as first_date')
            ->pluck('first_date', 'account_id');

        $pastMonths = [];
        foreach ($accounts as $account) {
            $first = ForecastCalendar::monthKey((string) ($firstMonths[$account->id] ?? $startDate));
            $pastMonths[$account->id] = array_values(array_filter(
                array_map(fn (int $offset): string => ForecastCalendar::monthKey(ForecastCalendar::monthStart($startDate, $offset)), range(-self::MEDIAN_MONTHS, -1)),
                fn (string $month): bool => $month >= $first,
            ));
        }

        return $pastMonths;
    }

    /**
     * Each account's spend by parent category (OTHER for uncategorized) and
     * month, in its owner's share: positive spend, lowered by refunds.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, string>  $parents
     * @return array<string, array<string, array<string, int>>> By account, bucket, then `Y-m` month.
     */
    private function spendByBucket(User $user, string $spaceId, Collection $accounts, string $startDate, array $parents): array
    {
        $transactions = $this->history->unplannedTransactions($user, $spaceId, ForecastCalendar::monthStart($startDate, -self::MEDIAN_MONTHS), $startDate, $accounts->modelKeys());
        $legs = $this->pairer->pair($transactions);
        $spend = [];

        foreach ($transactions as $transaction) {
            if (! $transaction->isExpenseSide() || ($legs[$transaction->id] ?? null)?->isInternal()) {
                continue;
            }

            $bucket = $transaction->category_id === null ? self::OTHER : ($parents[$transaction->category_id] ?? self::OTHER);
            $month = ForecastCalendar::monthKey($transaction->transaction_date->toDateString());
            $spend[$transaction->account_id][$bucket][$month] = ($spend[$transaction->account_id][$bucket][$month] ?? 0) - $transaction->account->shareOfAmount($transaction->amount);
        }

        return $spend;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return Collection<int, Account>
     */
    private function inUserCurrency(User $user, Collection $accounts): Collection
    {
        return $accounts->filter(fn (Account $account): bool => strcasecmp($account->currency_code, (string) $user->currency_code) === 0)->values();
    }

    /**
     * Each fitting budget's split over the accounts in the user's currency,
     * by the spend each had in its parent over the median months (or, with
     * none, in anything; or, with none at all, all on the first of them).
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, array<string, array<string, int>>>  $spend
     * @param  array<string, string>  $parents
     * @param  array<string, list<string>>  $pastMonths
     * @return array<string, array{limit: int, shares: array<string, float>}> By parent category id.
     */
    private function budgetShares(User $user, string $spaceId, Collection $accounts, array $spend, array $parents, array $pastMonths): array
    {
        $eligible = $this->inUserCurrency($user, $accounts);

        if ($eligible->isEmpty()) {
            return [];
        }

        $shares = [];

        foreach ($this->budgets->monthlyByParent($user, $spaceId, $parents) as $parent => $limit) {
            $weights = $this->weights($eligible, fn (string $accountId): int => $this->pastTotal($spend[$accountId][$parent] ?? [], $pastMonths[$accountId]))
                ?? $this->weights($eligible, fn (string $accountId): int => array_sum(array_map(fn (array $byMonth): int => $this->pastTotal($byMonth, $pastMonths[$accountId]), $spend[$accountId] ?? [])))
                ?? [$eligible->first()->id => 1.0];

            $shares[$parent] = ['limit' => $limit, 'shares' => $weights];
        }

        return $shares;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  callable(string): int  $weigh
     * @return array<string, float>|null Null when every account weighs nothing.
     */
    private function weights(Collection $accounts, callable $weigh): ?array
    {
        $weights = collect($accounts->modelKeys())->mapWithKeys(fn (string $accountId): array => [$accountId => max($weigh($accountId), 0)])->filter();
        $total = $weights->sum();

        return $total > 0 ? $weights->map(fn (int $weight): float => $weight / $total)->all() : null;
    }

    /**
     * The spend of the lines of each parent, by month, over the accounts a
     * budget can cover (those in the user's currency).
     *
     * @param  list<Occurrence>  $occurrences
     * @param  array<string, string|null>  $itemCategories
     * @param  array<string, string>  $parents
     * @param  list<string>  $budgetAccountIds
     * @return array<string, array<string, int>> By parent category id, then `Y-m` month.
     */
    private function concreteSpendByParent(array $occurrences, array $itemCategories, array $parents, array $budgetAccountIds): array
    {
        $budgetAccounts = array_flip($budgetAccountIds);
        $concrete = [];

        foreach ($occurrences as $occurrence) {
            $parent = $parents[$itemCategories[$occurrence->itemId] ?? ''] ?? null;

            if ($parent === null || $occurrence->amount >= 0 || ! isset($budgetAccounts[$occurrence->accountId])) {
                continue;
            }

            $month = ForecastCalendar::monthKey($occurrence->date);
            $concrete[$parent][$month] = ($concrete[$parent][$month] ?? 0) - $occurrence->amount;
        }

        return $concrete;
    }

    /**
     * The user's adjustments by account and bucket, latest start first.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, string>  $parents
     * @return array<string, array<string, list<ForecastEstimateAdjustment>>>
     */
    private function adjustments(User $user, string $spaceId, Collection $accounts, array $parents): array
    {
        $byBucket = [];

        $adjustments = ForecastEstimateAdjustment::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->whereIn('account_id', $accounts->modelKeys())
            ->orderByDesc('starts_on')
            ->get();

        foreach ($adjustments as $adjustment) {
            $bucket = $adjustment->category_id === null ? self::OTHER : ($parents[$adjustment->category_id] ?? self::OTHER);
            $byBucket[$adjustment->account_id][$bucket][] = $adjustment;
        }

        return $byBucket;
    }

    /**
     * The rows of one account by bucket. Small median rows fold into OTHER
     * unless the user adjusted them.
     *
     * @param  array<string, array<string, int>>  $spend
     * @param  array<string, array{limit: int, shares: array<string, float>}>  $budgetShares
     * @param  array<string, list<ForecastEstimateAdjustment>>  $adjustments
     * @param  list<string>  $pastMonths
     * @return array<string, EstimateRow>
     */
    private function rows(Account $account, array $spend, array $budgetShares, array $adjustments, array $pastMonths, string $startMonth): array
    {
        $budgeted = array_filter($budgetShares, fn (array $budget): bool => isset($budget['shares'][$account->id]));
        $buckets = array_unique(array_map(strval(...), [...array_keys($spend), ...array_keys($adjustments), ...array_keys($budgeted)]));
        $smallRow = Money::toMinor(self::SMALL_ROW_MAJOR_UNITS, $account->currency_code);
        $rows = [];

        foreach ($buckets as $bucket) {
            $row = new EstimateRow(
                $this->median($spend[$bucket] ?? [], $pastMonths),
                max($spend[$bucket][$startMonth] ?? 0, 0),
                $budgeted[$bucket]['limit'] ?? null,
                $budgeted[$bucket]['shares'][$account->id] ?? null,
            );
            $folds = $row->budgetLimit === null && $row->median < $smallRow && ! isset($adjustments[$bucket]);
            $target = $folds ? self::OTHER : $bucket;

            $rows[$target] = isset($rows[$target]) ? $rows[$target]->foldedWith($row) : $row;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $months  The horizon, the start month first.
     * @param  array<string, int>  $concrete  The parent's concrete spend by month.
     * @param  list<ForecastEstimateAdjustment>  $adjustments  Latest start first.
     * @return list<CategoryEstimate>
     */
    private function estimatesFor(string $accountId, string $bucket, EstimateRow $row, array $months, array $concrete, array $adjustments): array
    {
        $estimates = [];

        foreach ($months as $index => $month) {
            $adjustment = $this->adjustmentCovering($adjustments, $month);
            $planned = $adjustment === null ? $row->amountFor($concrete[$month] ?? 0) : max($adjustment->amount, 0);
            $spent = $index === 0 ? $row->spentNow : 0;
            $amount = max($planned - $spent, 0);

            if ($amount > 0 || $spent > 0 || $adjustment !== null) {
                $basis = $adjustment === null ? $row->basis() : ForecastEstimateBasis::Adjustment;
                $estimates[] = new CategoryEstimate($accountId, $bucket === self::OTHER ? null : $bucket, $month, $amount, $basis, $planned, $spent, $adjustment === null ? $row->budgetAmount() : null);
            }
        }

        return $estimates;
    }

    /**
     * @param  list<ForecastEstimateAdjustment>  $adjustments  Latest start first.
     */
    private function adjustmentCovering(array $adjustments, string $month): ?ForecastEstimateAdjustment
    {
        $first = $month.'-01';
        $last = ForecastCalendar::horizonEnd($first, 1);

        foreach ($adjustments as $adjustment) {
            if ($adjustment->starts_on->toDateString() <= $last && ($adjustment->ends_on === null || $adjustment->ends_on->toDateString() >= $first)) {
                return $adjustment;
            }
        }

        return null;
    }

    /**
     * The median of the months that count, a month without spend counting as
     * zero and one with more refunds than spend as nothing.
     *
     * @param  array<string, int>  $byMonth
     * @param  list<string>  $pastMonths
     */
    private function median(array $byMonth, array $pastMonths): int
    {
        if ($pastMonths === []) {
            return 0;
        }

        return (int) round(collect($pastMonths)->map(fn (string $month): int => max($byMonth[$month] ?? 0, 0))->median());
    }

    /**
     * @param  array<string, int>  $byMonth
     * @param  list<string>  $pastMonths
     */
    private function pastTotal(array $byMonth, array $pastMonths): int
    {
        return array_sum(array_map(fn (string $month): int => $byMonth[$month] ?? 0, $pastMonths));
    }

    /**
     * The `Y-m` keys of the horizon's months, the start month first.
     *
     * @return list<string>
     */
    private function monthKeys(string $startDate, int $horizonMonths): array
    {
        return array_map(fn (int $offset): string => ForecastCalendar::monthKey(ForecastCalendar::monthStart($startDate, $offset)), range(0, $horizonMonths - 1));
    }
}
