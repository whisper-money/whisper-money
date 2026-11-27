<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\User;
use App\Services\Forecast\Data\CalendarMonth;
use App\Services\Forecast\Data\TransferEstimate;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the user usually moves between their own accounts each month: the
 * salary landing in one account and part of it going on to the one the card
 * is paid from, a monthly saving, a contribution to an index fund.
 *
 * Transfers are the pairs TransferPairer finds over every account of the
 * space, read the way EstimateCalculator reads its history (what paid a
 * line the forecast still projects, or was marked a one-off, is left out,
 * so a manual transfer line is never counted twice). For each pair of
 * accounts, each month nets what went one way against what came back; the
 * figure is the median of the three complete months before the start (only
 * those since both accounts' first transaction), and it goes the way the
 * median says. A pair whose median is zero moves nothing. It lands whole on
 * its usual day (see EstimateTiming::incomeDay()), clamped to the month
 * and, in the start month, to the days left, and that month only moves what
 * is left after what already went.
 *
 * A pair counts when one of its accounts is in the forecast: a transfer to
 * a card, a loan or a house it leaves out is money leaving it (paying off
 * the card each month becomes an outflow of the checking account it is paid
 * from), one from them is money coming in. A transfer whose other leg is
 * not in the app is not internal; it stays in its category, see
 * EstimateCalculator.
 */
class TransferEstimator
{
    private const MEDIAN_MONTHS = 3;

    public function __construct(
        private readonly ForecastHistory $history,
        private readonly TransferPairer $pairer,
        private readonly EstimateTiming $timing,
    ) {}

    /**
     * @param  Collection<int, Account>  $included  Every account of the space the forecast includes.
     * @return list<TransferEstimate>
     */
    public function estimate(User $user, string $spaceId, Collection $included, string $startDate, int $horizonMonths): array
    {
        $moves = $this->movesByPair($user, $spaceId, $included, $startDate);

        if ($moves === []) {
            return [];
        }

        $accountIds = $this->accountIdsOf($moves);
        $firstDates = $this->history->firstTransactionDates($user, $accountIds);
        $names = Account::query()->where('user_id', $user->id)->whereIn('id', $accountIds)->pluck('name', 'id')->all();
        $estimates = [];

        foreach ($moves as $pair => $byMonthAndDay) {
            [$from, $to] = explode('|', (string) $pair);
            $pastMonths = $this->pastMonths($startDate, max($firstDates[$from] ?? $startDate, $firstDates[$to] ?? $startDate));
            $median = $this->median($byMonthAndDay, $pastMonths);

            if ($median < 0) {
                [$from, $to, $median] = [$to, $from, -$median];
                $byMonthAndDay = array_map(fn (array $byDay): array => array_map(fn (int $amount): int => -$amount, $byDay), $byMonthAndDay);
            }

            if ($median > 0) {
                $moved = max(array_sum($byMonthAndDay[ForecastCalendar::monthKey($startDate)] ?? []), 0);
                $day = $this->timing->incomeDay($byMonthAndDay, $pastMonths) ?? 1;
                $pairNames = [(string) ($names[$from] ?? ''), (string) ($names[$to] ?? '')];

                array_push($estimates, ...$this->monthly($from, $to, $pairNames, $median, $moved, $day, $startDate, $horizonMonths));
            }
        }

        return $estimates;
    }

    /**
     * What moved between each pair of accounts, at least one of them
     * included, by month and day. A pair is keyed by its two account ids in
     * order, and an amount is positive when it went from the first to the
     * second.
     *
     * @param  Collection<int, Account>  $included
     * @return array<string, array<string, array<int, int>>> By pair, `Y-m` month, then day.
     */
    private function movesByPair(User $user, string $spaceId, Collection $included, string $startDate): array
    {
        $transactions = $this->history->estimableTransactions($user, $spaceId, ForecastCalendar::monthStart($startDate, -self::MEDIAN_MONTHS), $startDate);
        $legs = $this->pairer->pair($transactions);
        $includedIds = array_flip($included->modelKeys());
        $moves = [];

        foreach ($transactions as $transaction) {
            $from = $transaction->account_id;
            $to = ($legs[$transaction->id] ?? null)?->counterpartAccountId;

            if ($transaction->amount >= 0 || $to === null || (! isset($includedIds[$from]) && ! isset($includedIds[$to]))) {
                continue;
            }

            $forward = strcmp($from, $to) < 0;
            $pair = $forward ? $from.'|'.$to : $to.'|'.$from;
            $date = $transaction->transaction_date->toDateString();
            $month = ForecastCalendar::monthKey($date);
            $day = (int) substr($date, 8, 2);
            $moves[$pair][$month][$day] = ($moves[$pair][$month][$day] ?? 0) + ($forward ? -$transaction->amount : $transaction->amount);
        }

        return $moves;
    }

    /**
     * Every account a pair of the moves names.
     *
     * @param  array<string, mixed>  $moves  Keyed by pair, see movesByPair().
     * @return list<string>
     */
    private function accountIdsOf(array $moves): array
    {
        $ids = [];

        foreach (array_keys($moves) as $pair) {
            array_push($ids, ...explode('|', (string) $pair));
        }

        return array_values(array_unique($ids));
    }

    /**
     * The complete months before the start the median is taken over: the
     * last three, from the month of `$firstDate` on.
     *
     * @return list<string>
     */
    private function pastMonths(string $startDate, string $firstDate): array
    {
        $first = ForecastCalendar::monthKey($firstDate);

        return array_values(array_filter(
            array_map(fn (int $offset): string => ForecastCalendar::monthKey(ForecastCalendar::monthStart($startDate, $offset)), range(-self::MEDIAN_MONTHS, -1)),
            fn (string $month): bool => $month >= $first,
        ));
    }

    /**
     * The median of what each month netted, a month without a transfer
     * counting as zero.
     *
     * @param  array<string, array<int, int>>  $byMonthAndDay
     * @param  list<string>  $pastMonths
     */
    private function median(array $byMonthAndDay, array $pastMonths): int
    {
        if ($pastMonths === []) {
            return 0;
        }

        return (int) round(collect($pastMonths)->map(fn (string $month): int => array_sum($byMonthAndDay[$month] ?? []))->median());
    }

    /**
     * One estimate per month of the horizon with something to move or that
     * already moved something.
     *
     * @param  array{string, string}  $names  The accounts' names, from first.
     * @return list<TransferEstimate>
     */
    private function monthly(string $from, string $to, array $names, int $median, int $moved, int $day, string $startDate, int $horizonMonths): array
    {
        $estimates = [];

        for ($offset = 0; $offset < $horizonMonths; $offset++) {
            $month = CalendarMonth::startingOn($offset === 0 ? $startDate : ForecastCalendar::monthStart($startDate, $offset));
            $alreadyMoved = $offset === 0 ? $moved : 0;
            $amount = max($median - $alreadyMoved, 0);

            if ($amount > 0 || $alreadyMoved > 0) {
                $estimates[] = new TransferEstimate($from, $to, $names[0], $names[1], $month->key, $amount, $median, $alreadyMoved, $month->date(max(min($day, $month->lastDay), $month->firstDay)));
            }
        }

        return $estimates;
    }
}
