<?php

namespace App\Services\Forecast\Backtest;

use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastCalendar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;

/**
 * The users the forecast backtest learns from: the active, well-kept ones,
 * with the criteria of the original forecast analysis. A user qualifies with
 * transactions in each of the HISTORY_MONTHS complete months before today,
 * at least RECENT_TRANSACTIONS of them in the last RECENT_MONTHS complete
 * months, and most of that recent money (by absolute amount) in a category.
 *
 * A user who already set up a forecast (lines, adjustments or one-offs) is
 * left out: what they entered by hand is known today, not at the cut-offs,
 * so it would leak the future into the past.
 */
class BacktestCohort
{
    private const HISTORY_MONTHS = 6;

    private const RECENT_MONTHS = 3;

    private const RECENT_TRANSACTIONS = 60;

    public function __construct(
        public readonly int $active = 0,
        public readonly int $withForecast = 0,
        /** @var Collection<int, User> */
        public readonly Collection $users = new Collection,
    ) {}

    /**
     * @param  string  $today  `Y-m-d`.
     * @param  float  $minCategorisedShare  Between 0 and 1.
     */
    public static function select(string $today, float $minCategorisedShare): self
    {
        $active = User::query()->whereIn('id', self::activeUserIds($today, $minCategorisedShare))->pluck('id')->map(strval(...))->all();
        $withForecast = array_values(array_intersect($active, self::usersWithForecastData()));
        $userIds = array_values(array_diff($active, $withForecast));

        return new self(
            count($active),
            count($withForecast),
            User::query()->whereIn('id', $userIds)->orderBy('id')->get(),
        );
    }

    /**
     * @return list<string>
     */
    private static function activeUserIds(string $today, float $minCategorisedShare): array
    {
        $historyStart = ForecastCalendar::monthStart($today, -self::HISTORY_MONTHS);
        $recentStart = ForecastCalendar::monthStart($today, -self::RECENT_MONTHS);
        $end = ForecastCalendar::monthStart($today);
        $recent = 'transaction_date >= ?';

        return Transaction::query()
            ->toBase()
            ->where('transaction_date', '>=', $historyStart)
            ->where('transaction_date', '<', $end)
            ->groupBy('user_id')
            ->havingRaw('count(distinct substr(transaction_date, 1, 7)) = ?', [self::HISTORY_MONTHS])
            ->havingRaw("sum(case when {$recent} then 1 else 0 end) >= ?", [$recentStart, self::RECENT_TRANSACTIONS])
            ->havingRaw(
                "sum(case when {$recent} and category_id is not null then abs(amount) else 0 end) >= ? * sum(case when {$recent} then abs(amount) else 0 end)",
                [$recentStart, $minCategorisedShare, $recentStart],
            )
            ->pluck('user_id')
            ->map(strval(...))
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function usersWithForecastData(): array
    {
        $excluded = Transaction::query()->toBase()
            ->whereIn('id', fn (Builder $query) => $query->from((new ForecastExcludedTransaction)->getTable())->select('transaction_id'))
            ->distinct()
            ->pluck('user_id');

        return ForecastItem::query()->toBase()->distinct()->pluck('user_id')
            ->merge(ForecastEstimateAdjustment::query()->toBase()->distinct()->pluck('user_id'))
            ->merge($excluded)
            ->map(strval(...))
            ->unique()
            ->values()
            ->all();
    }
}
