<?php

namespace App\Services\SavingsGoals;

use App\Enums\MonthlyTargetType;
use App\Models\SavingsGoalPeriod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowSummaryService;
use Carbon\Carbon;

/**
 * Turns a monthly goal's target into money for a given month.
 *
 * A share-of-income target is measured against the average income of the
 * complete calendar months before the one being opened, not against the month
 * itself: plenty of people are paid on the last day, and a target built on the
 * month's own income would read 0 until then.
 */
class MonthlyTargetResolver
{
    /**
     * How many complete months before the opened one make up the income base.
     */
    private const INCOME_BASE_MONTHS = 3;

    /**
     * Income per user and month, memoized for the lifetime of this instance: a
     * page that lists several share-of-income goals asks for the same months.
     *
     * @var array<string, int>
     */
    private array $incomeCache = [];

    public function __construct(private CashflowSummaryService $cashflow) {}

    /**
     * The target in money, or null when a share-of-income target has no
     * complete month to stand on yet and has to be followed live.
     */
    public function resolveAtOpen(User $user, MonthlyTargetType $type, ?int $amount, ?float $rate, Carbon $month): ?int
    {
        if ($type === MonthlyTargetType::Amount) {
            return (int) $amount;
        }

        $base = $this->averageIncomeBefore($user, $month);

        return $base === null ? null : self::applyRate($rate, $base);
    }

    /**
     * The target a period is held to right now: the frozen one, or — while it
     * has none — the rate applied to what came in that month so far.
     */
    public function targetFor(SavingsGoalPeriod $period, User $user): int
    {
        if ($period->resolved_target_amount !== null) {
            return $period->resolved_target_amount;
        }

        if ($period->target_type === MonthlyTargetType::Amount) {
            return (int) $period->target_amount;
        }

        return self::applyRate($period->target_rate, $this->incomeIn($user, $period->month));
    }

    /**
     * Average monthly income over the up-to-three complete months before
     * $month, counting only months the user already had data in. Null when
     * there is no such month.
     */
    public function averageIncomeBefore(User $user, Carbon $month): ?int
    {
        $firstMonth = $this->firstActivityMonth($user);
        $lastComplete = $month->copy()->startOfMonth()->subMonth();

        if ($firstMonth === null || $firstMonth->gt($lastComplete)) {
            return null;
        }

        $from = $lastComplete->copy()->subMonths(self::INCOME_BASE_MONTHS - 1)->max($firstMonth);
        $months = $this->cashflow->forMonths($user->id, $user->currency_code, $from, $lastComplete->copy()->endOfMonth());
        $average = array_sum(array_column($months, 'income')) / max(1, count($months));

        return max(0, (int) round($average));
    }

    /**
     * Income of one calendar month, in the user's currency.
     */
    public function incomeIn(User $user, Carbon $month): int
    {
        $key = $user->id.'|'.$month->format('Y-m');

        return $this->incomeCache[$key] ??= max(0, (int) ($this->cashflow->forMonths(
            $user->id,
            $user->currency_code,
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
        )[$month->format('Y-m')]['income'] ?? 0));
    }

    private static function applyRate(?float $rate, int $base): int
    {
        return (int) round($base * (float) $rate / 100);
    }

    /**
     * The month of the user's earliest transaction that counts towards totals.
     */
    private function firstActivityMonth(User $user): ?Carbon
    {
        $earliest = Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->countingTowardsTotals()
            ->min('transactions.transaction_date');

        return $earliest === null ? null : Carbon::parse($earliest)->startOfMonth();
    }
}
