<?php

namespace App\Services\SavingsGoals;

use App\Enums\SavingsGoalMonthStatus;
use App\Models\User;

/**
 * The month in progress across the user's running monthly savings goals, and
 * how every goal did last month: what the dashboard's monthly savings card
 * draws, and what the API and the MCP serve from the same figures. Goals of
 * every space the user is in count, but only the user's own.
 */
class CurrentMonthSavings
{
    public function __construct(
        private MonthlySavingsGoalStats $stats,
        private MonthlySavingsGoalTotals $totals,
        private SavingsGoalPresenter $presenter,
    ) {}

    /**
     * The month in progress added up over the running goals, the goals
     * themselves and last month's verdicts.
     *
     * The sums leave out a partial month (a goal created in the month's last
     * days), which has no target to add up, as the dashboard card does. Each
     * goal's month comes as {@see SavingsGoalPresenter::currentMonth()} shapes
     * it, so it reads like the savings goals list.
     *
     * @return array{month: string, saved: int, target: int, days_left: int, goals: list<array{id: string, name: string, current: array<string, mixed>|null}>, last_month: array{month: string, met: int, total: int}|null}
     */
    public function forUser(User $user): array
    {
        $today = $user->localToday();
        $goals = $this->runningGoals($user);
        $addedUp = array_filter(
            array_map(fn (array $goal): ?array => $goal['monthly']['current'], $goals),
            fn (?array $month): bool => $month !== null && ! in_array($month['status'], [SavingsGoalMonthStatus::Partial, SavingsGoalMonthStatus::Archived], true),
        );

        return [
            'month' => $today->format('Y-m'),
            'saved' => array_sum(array_column($addedUp, 'saved')),
            'target' => array_sum(array_column($addedUp, 'target')),
            'days_left' => (int) $today->diffInDays($today->copy()->endOfMonth()) + 1,
            'goals' => array_map(fn (array $goal): array => [
                'id' => $goal['id'],
                'name' => $goal['name'],
                'current' => $this->presenter->currentMonth($goal['monthly']['current']),
            ], $goals),
            'last_month' => $this->lastMonth($user),
        ];
    }

    /**
     * The monthly goals still running, with this month only. An archived goal
     * has nothing left to save towards. No history, no goal fields the
     * dashboard card does not draw.
     *
     * @return list<array{id: string, name: string, monthly: array{current: array<string, mixed>|null}}>
     */
    public function runningGoals(User $user): array
    {
        return array_map(fn (array $goal): array => [
            'id' => $goal['id'],
            'name' => $goal['name'],
            'monthly' => ['current' => $goal['monthly']['current']],
        ], $this->stats->present(
            $user->savingsGoals()->monthly()->notArchived()->listed()->get(),
        ));
    }

    /**
     * How every monthly goal did last month, met of judged. Archived goals
     * count: the same figure the cashflow card and the monthly summary give
     * for that month. Null when no goal was judged in it.
     *
     * @return array{month: string, met: int, total: int}|null
     */
    public function lastMonth(User $user): ?array
    {
        return $this->totals->verdicts(
            $this->stats->presentForUser($user),
            $user->localToday()->subMonthNoOverflow(),
        );
    }
}
