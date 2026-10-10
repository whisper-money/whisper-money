<?php

namespace App\Services\SavingsGoals;

use App\Enums\MonthlyTargetType;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Models\Transaction;
use App\Services\CashflowSummaryService;
use Carbon\Carbon;

/**
 * Turns a monthly goal's target into money for a given month.
 *
 * A share-of-income target is measured against the average income of the
 * complete calendar months before the one being opened, not against the month
 * itself: plenty of people are paid on the last day, and a target built on the
 * month's own income would read 0 until then.
 *
 * Income is the goal's own space's, every member's included: a goal set aside
 * in a shared household space is measured against that household's income,
 * the same way what it saved counts tags from any member.
 *
 * Months are calendar months in the app's timezone, like budget periods.
 */
class MonthlyTargetResolver
{
    /**
     * How many complete months before the opened one make up the income base.
     */
    private const INCOME_BASE_MONTHS = 3;

    /**
     * Income figures per user and month, memoized for the lifetime of this
     * instance: a page listing several share-of-income goals, or the daily
     * command going through one user's goals, asks for the same months. An
     * instance is resolved per request or per command run, so a figure is
     * never older than that.
     *
     * @var array<string, int|null>
     */
    private array $cache = [];

    public function __construct(private CashflowSummaryService $cashflow) {}

    /**
     * The frozen target of a month that is opening, and the income it came
     * from. Both are null when a share-of-income target has no income to stand
     * on yet — no complete month, or none with any income — and has to be
     * followed live.
     *
     * @return array{resolved_target_amount: ?int, income_base: ?int}
     */
    public function resolveAtOpen(SavingsGoal $goal, Carbon $month): array
    {
        if ($goal->monthly_target_type === MonthlyTargetType::Amount) {
            return ['resolved_target_amount' => (int) $goal->monthly_target_amount, 'income_base' => null];
        }

        $base = $this->averageIncomeBefore($goal, $month);

        return [
            'resolved_target_amount' => $base === null ? null : self::applyRate($goal->monthly_target_rate, $base),
            'income_base' => $base,
        ];
    }

    /**
     * The target a period is held to right now, and the income behind it: the
     * frozen pair, or — while it has none — the rate applied to what came in
     * that month so far.
     *
     * @return array{resolved_target_amount: int, income_base: ?int}
     */
    public function current(SavingsGoalPeriod $period, SavingsGoal $goal): array
    {
        if ($period->resolved_target_amount !== null) {
            return ['resolved_target_amount' => $period->resolved_target_amount, 'income_base' => $period->income_base];
        }

        if ($period->target_type === MonthlyTargetType::Amount) {
            return ['resolved_target_amount' => (int) $period->target_amount, 'income_base' => null];
        }

        $income = $this->incomeIn($goal, $period->month);

        return ['resolved_target_amount' => self::applyRate($period->target_rate, $income), 'income_base' => $income];
    }

    /**
     * Average monthly income over the up-to-three complete months before
     * $month, counting only months the goal's space already had data in. Null
     * when there is no such month, or when they brought in nothing: a target
     * of 0 would be met by saving nothing, so it is followed live instead.
     */
    private function averageIncomeBefore(SavingsGoal $goal, Carbon $month): ?int
    {
        $key = 'average|'.$goal->user_id.'|'.$goal->space_id.'|'.$month->format('Y-m');

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $firstMonth = $this->firstActivityMonth($goal);
        $lastComplete = $month->copy()->startOfMonth()->subMonth();

        if ($firstMonth === null || $firstMonth->gt($lastComplete)) {
            return $this->cache[$key] = null;
        }

        $from = $lastComplete->copy()->subMonths(self::INCOME_BASE_MONTHS - 1)->max($firstMonth);
        $months = $this->incomeMonths($goal, $from, $lastComplete);
        $average = (int) round(array_sum(array_column($months, 'income')) / max(1, count($months)));

        return $this->cache[$key] = $average > 0 ? $average : null;
    }

    /**
     * Income of one calendar month, in the user's currency.
     */
    private function incomeIn(SavingsGoal $goal, Carbon $month): int
    {
        $key = 'month|'.$goal->user_id.'|'.$goal->space_id.'|'.$month->format('Y-m');

        return $this->cache[$key] ??= max(0, (int) ($this->incomeMonths($goal, $month, $month)[$month->format('Y-m')]['income'] ?? 0));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function incomeMonths(SavingsGoal $goal, Carbon $from, Carbon $to): array
    {
        return $this->cashflow->forMonths(
            $goal->user_id,
            $goal->user->currency_code,
            $from->copy()->startOfMonth(),
            $to->copy()->endOfMonth(),
            $goal->space_id,
        );
    }

    /**
     * A share of an income, in cents.
     */
    public static function applyRate(?float $rate, int $base): int
    {
        return (int) round($base * (float) $rate / 100);
    }

    /**
     * The month of the earliest transaction of the goal's space that counts
     * towards totals.
     */
    private function firstActivityMonth(SavingsGoal $goal): ?Carbon
    {
        $earliest = Transaction::query()
            ->when(
                $goal->space_id === null,
                fn ($query) => $query->where('transactions.user_id', $goal->user_id),
                fn ($query) => $query->forSpace($goal->space_id),
            )
            ->countingTowardsTotals()
            ->min('transactions.transaction_date');

        return $earliest === null ? null : Carbon::parse($earliest)->startOfMonth();
    }
}
