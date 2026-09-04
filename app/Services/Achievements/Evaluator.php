<?php

namespace App\Services\Achievements;

use App\Models\User;

/**
 * Reads a user's history and says which medals it contains, and when.
 *
 * Two kinds of rule live here. A milestone is a state the money reached, so it
 * is looked for across the whole history and dated with the month it really
 * happened — someone who crossed 50k in 2023 gets a 2023 medal. A streak is a
 * habit kept, so it only counts months from the launch on: nobody earns a
 * twelve-month streak on their first day for a year they were never being
 * counted through.
 *
 * Nothing here writes: it hands back what it found, and {@see Awarder} decides
 * what is new.
 */
class Evaluator
{
    public function __construct(
        private Catalog $catalog,
        private Ladders $ladders,
        private HistoryBuilder $builder,
    ) {}

    /**
     * @return array<string, Unlock> keyed by medal key
     */
    public function for(User $user): array
    {
        $history = $this->builder->for($user);
        $visits = $this->visits($user);

        // Visits are the one thing that does not need a history: somebody who
        // has recorded nothing yet is still showing up, and that is the streak
        // worth telling them about first.
        if ($history->isEmpty()) {
            return $visits;
        }

        return array_merge(
            $visits,
            $this->transactions($history),
            $this->categorized($history),
            $this->hygiene($history),
            $this->money($history),
            $this->savingsRate($history),
            $this->safety($history),
            $this->streaks($history),
            $this->momentum($history),
        );
    }

    /**
     * The first transaction, then the count of them.
     *
     * @return array<string, Unlock>
     */
    private function transactions(History $history): array
    {
        $unlocks = [];
        $running = [];
        $total = 0;

        foreach ($history->transactions as $month => $count) {
            $total += $count;
            $running[$month] = $total;
        }

        $firstMonth = $this->firstMonthWith($history->transactions);

        if ($firstMonth !== null) {
            $unlocks['transactions.1'] = Unlock::event($firstMonth);
        }

        // Every rung but the first, which is the transaction itself.
        foreach (array_slice($this->catalog->tiers('transactions'), 1) as $tier) {
            $crossing = $this->firstCrossing($running, (float) $this->threshold("transactions.{$tier}"));

            if ($crossing !== null) {
                $unlocks["transactions.{$tier}"] = Unlock::count($crossing['month'], (int) $crossing['value']);
            }
        }

        return $unlocks;
    }

    /**
     * Closed months in a row with nothing left uncategorized. A month with no
     * transactions at all breaks nothing and counts for nothing.
     *
     * @return array<string, Unlock>
     */
    private function categorized(History $history): array
    {
        $unlocks = [];
        $run = 0;

        foreach ($history->transactions as $month => $count) {
            $run = $count > 0 && ($history->uncategorized[$month] ?? 0) === 0 ? $run + 1 : 0;

            foreach ($this->catalog->tiers('categorized') as $tier) {
                $key = "categorized.{$tier}";

                if (! isset($unlocks[$key]) && $run >= $this->threshold($key)) {
                    $unlocks[$key] = Unlock::count($month, $run);
                }
            }
        }

        return $unlocks;
    }

    /**
     * The one-off milestones, each dated by the month it happened.
     *
     * @return array<string, Unlock>
     */
    private function hygiene(History $history): array
    {
        $events = [
            'hygiene.1' => $this->firstMonthWith($history->transactions),
            'hygiene.2' => $history->events['first_bank'] ?? null,
            'hygiene.3' => $history->events['three_accounts'] ?? null,
            'hygiene.4' => $history->events['first_rule'] ?? null,
            'hygiene.5' => $history->events['first_budget'] ?? null,
        ];

        $unlocks = [];

        foreach ($events as $key => $month) {
            if ($month !== null) {
                $unlocks[$key] = Unlock::event($month);
            }
        }

        $goalMonth = $history->events['goal_reached'] ?? null;

        if ($goalMonth !== null && $history->goalReachedAmount !== null) {
            $unlocks['hygiene.6'] = Unlock::money($goalMonth, $history->goalReachedAmount, $history->currency);
        }

        return $unlocks;
    }

    /**
     * The four money ladders. Thresholds come from the reader's currency, so
     * `net_worth.4` is the same rung for everyone and a different figure.
     *
     * @return array<string, Unlock>
     */
    private function money(History $history): array
    {
        return array_merge(
            $this->ladder($history, 'monthly_saving', 'monthly', $history->series('savings')),
            $this->ladder($history, 'monthly_investing', 'monthly', $history->series('investments')),
            $this->ladder($history, 'yearly_saving', 'yearly', $this->runningYears($history->series('savings'))),
            $this->ladder($history, 'net_worth', 'net_worth', $history->netWorth),
        );
    }

    /**
     * @param  array<string, int|float>  $series
     * @return array<string, Unlock>
     */
    private function ladder(History $history, string $track, string $ladder, array $series): array
    {
        $unlocks = [];

        foreach ($this->ladders->rungs($ladder, $history->currency) as $index => $threshold) {
            $key = $track.'.'.($index + 1);
            $crossing = $this->firstCrossing($series, (float) $threshold);

            if ($crossing !== null) {
                $unlocks[$key] = Unlock::money($crossing['month'], (int) $crossing['value'], $history->currency);
            }
        }

        return $unlocks;
    }

    /**
     * The same series as a running total that restarts every January, so a
     * yearly ladder is crossed in the month it was actually crossed.
     *
     * @param  array<string, int|float>  $series
     * @return array<string, int|float>
     */
    private function runningYears(array $series): array
    {
        $running = [];
        $total = 0;
        $year = null;

        foreach ($series as $month => $value) {
            $thisYear = substr($month, 0, 4);
            $total = $thisYear === $year ? $total + $value : $value;
            $year = $thisYear;
            $running[$month] = $total;
        }

        return $running;
    }

    /**
     * @return array<string, Unlock>
     */
    private function savingsRate(History $history): array
    {
        $unlocks = [];
        $rates = $history->series('savings_rate');

        foreach ($this->catalog->tiers('savings_rate') as $tier) {
            $key = "savings_rate.{$tier}";
            $crossing = $this->firstCrossing($rates, (float) $this->threshold($key));

            if ($crossing !== null) {
                $unlocks[$key] = Unlock::rate($crossing['month'], (float) $crossing['value']);
            }
        }

        return $unlocks;
    }

    /**
     * A loan cleared, and a cushion measured in months of spending.
     *
     * @return array<string, Unlock>
     */
    private function safety(History $history): array
    {
        $unlocks = [];
        $paidOff = $history->events['loan_paid_off'] ?? null;

        if ($paidOff !== null) {
            $unlocks['safety.1'] = Unlock::event($paidOff);
        }

        // The first is the loan; the rest are months of cover.
        foreach (array_slice($this->catalog->tiers('safety'), 1) as $tier) {
            $key = "safety.{$tier}";
            $covered = $this->firstMonthCovering($history, (int) $this->threshold($key));

            if ($covered !== null) {
                $unlocks[$key] = Unlock::money($covered['month'], $covered['value'], $history->currency);
            }
        }

        return $unlocks;
    }

    /**
     * The first month whose liquid balance covered this many months of the
     * average spending of the six months before it.
     *
     * @return array{month: string, value: int}|null
     */
    private function firstMonthCovering(History $history, int $months): ?array
    {
        $expenses = $history->series('expense');

        foreach ($history->monthKeys() as $index => $month) {
            $average = $this->averageOfPrevious($expenses, $index, 6);
            $liquid = $history->liquid[$month] ?? 0;

            if ($average > 0 && $liquid >= $average * $months) {
                return ['month' => $month, 'value' => $liquid];
            }
        }

        return null;
    }

    /**
     * Months in a row in the black, counted from the launch month on: a streak
     * is a habit kept while the medals were there to keep it for.
     *
     * @return array<string, Unlock>
     */
    private function streaks(History $history): array
    {
        $from = (string) config('achievements.streaks_from');
        $unlocks = [];
        $run = 0;

        foreach ($history->series('net') as $month => $net) {
            if ($month < $from) {
                continue;
            }

            $run = $net > 0 ? $run + 1 : 0;

            foreach ($this->catalog->tiers('streaks') as $tier) {
                $key = "streaks.{$tier}";

                if (! isset($unlocks[$key]) && $run >= $this->threshold($key)) {
                    $unlocks[$key] = Unlock::count($month, $run);
                }
            }
        }

        return $unlocks;
    }

    /**
     * Days, and weeks, in a row opening the app, read off the runs the
     * middleware keeps.
     *
     * @return array<string, Unlock>
     */
    private function visits(User $user): array
    {
        $on = $user->last_active_at?->toDateString();

        return array_merge(
            $this->visitRun('visits', (int) $user->longest_visit_streak, $on),
            $this->visitRun('visit_weeks', (int) $user->longest_visit_week_streak, $on),
        );
    }

    /**
     * The longest run rather than the current one, because the sweep is nightly
     * and a run that peaked and broke between two of them still happened. Dated
     * to the last visit, which is the day the run reached its length.
     *
     * @return array<string, Unlock>
     */
    private function visitRun(string $track, int $best, ?string $on): array
    {
        if ($best < 1 || $on === null) {
            return [];
        }

        $unlocks = [];

        foreach ($this->catalog->tiers($track) as $tier) {
            $key = "{$track}.{$tier}";

            if ($best >= $this->threshold($key)) {
                $unlocks[$key] = Unlock::run($on, $best);
            }
        }

        return $unlocks;
    }

    /**
     * Beating your own recent form: a month that saved more than the six before
     * it averaged, and a year that moved net worth by a tenth.
     *
     * @return array<string, Unlock>
     */
    private function momentum(History $history): array
    {
        $unlocks = [];
        $savings = $history->series('savings');

        foreach ($history->monthKeys() as $index => $month) {
            $average = $this->averageOfPrevious($savings, $index, 6);

            if ($average > 0 && ($savings[$month] ?? 0) > $average) {
                $unlocks['momentum.1'] = Unlock::money($month, (int) $savings[$month], $history->currency);

                break;
            }
        }

        $growth = $this->firstYearOfGrowth($history, (float) $this->threshold('momentum.2'));

        if ($growth !== null) {
            $unlocks['momentum.2'] = Unlock::rate($growth['month'], $growth['value']);
        }

        return $unlocks;
    }

    /**
     * @return array{month: string, value: float}|null
     */
    private function firstYearOfGrowth(History $history, float $percent): ?array
    {
        $months = $history->monthKeys();

        foreach ($months as $index => $month) {
            $yearAgo = $history->netWorth[$months[$index - 12] ?? ''] ?? null;
            $now = $history->netWorth[$month] ?? 0;

            if ($yearAgo === null || $yearAgo <= 0) {
                continue;
            }

            $growth = round((($now - $yearAgo) / $yearAgo) * 100, 2);

            if ($growth >= $percent) {
                return ['month' => $month, 'value' => $growth];
            }
        }

        return null;
    }

    /**
     * The average of the given number of months before this one. Zero until
     * there are that many, so nothing is earned against half a window.
     *
     * @param  array<string, int|float>  $series
     */
    private function averageOfPrevious(array $series, int $index, int $window): float
    {
        if ($index < $window) {
            return 0.0;
        }

        $previous = array_slice(array_values($series), $index - $window, $window);

        return array_sum($previous) / $window;
    }

    /**
     * @param  array<string, int|float>  $series
     * @return array{month: string, value: int|float}|null
     */
    private function firstCrossing(array $series, float $threshold): ?array
    {
        foreach ($series as $month => $value) {
            if ($value >= $threshold) {
                return ['month' => (string) $month, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $series
     */
    private function firstMonthWith(array $series): ?string
    {
        foreach ($series as $month => $count) {
            if ($count > 0) {
                return (string) $month;
            }
        }

        return null;
    }

    private function threshold(string $key): int|float
    {
        $definition = $this->catalog->find($key);

        return $definition === null ? 0 : ($definition->threshold ?? 0);
    }
}
