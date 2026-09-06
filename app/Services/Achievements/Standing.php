<?php

namespace App\Services\Achievements;

use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where a reader stands right now, per track.
 *
 * Only five of the thirteen tracks are here, and that is the whole point: each
 * of these is a column, a count or a figure somebody already wrote down, so the
 * progress screen can put a number under the next medal without paying for one.
 * The other eight are money, and their current value only exists inside the
 * {@see History} the nightly sweep builds — a balance walk per account and a
 * month of exchange rates, which is not something a page render can afford.
 *
 * The figures are live while the medals are awarded overnight, so a reader can
 * already stand past a threshold with the medal still locked. The screen says
 * so rather than pretending the number is smaller.
 */
class Standing
{
    /**
     * @return array<string, int> the current figure, keyed by track
     */
    public function for(User $user, ?MonthlySummary $report): array
    {
        // One grouped query answers both transaction tracks, so the count and
        // the run can never disagree about which months they read.
        $months = $this->closedMonths($user);

        return [
            // The longest run rather than the current one, because that is what
            // the medal is awarded on: a bar reading the live streak would fall
            // back on a broken run without the medal moving any further away.
            'visits' => (int) $user->longest_visit_streak,
            'visit_weeks' => (int) $user->longest_visit_week_streak,
            'transactions' => (int) $months->sum('total'),
            'categorized' => $this->categorizedRun($months),
            // Read off the last report rather than recomputed, like the live
            // streak in the overview, so the two cannot disagree.
            'streaks' => (int) ($report?->figure('streak_months') ?? 0),
        ];
    }

    /**
     * What a reader recorded in each closed month, and how much of it was left
     * without a category, keyed by month.
     *
     * The month in progress is left out, for the same reason the visit bar
     * reads the longest run: the medal is awarded on what the nightly sweep
     * sees, and {@see HistoryBuilder} stops at the last closed month. Counting
     * today's transactions would fill the bar, and promise the medal tonight,
     * up to a month before either is true.
     *
     * @return Collection<string, object>
     */
    private function closedMonths(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('transaction_date', '<', now()->startOfMonth())
            ->selectRaw("date_format(transaction_date, '%Y-%m') as period")
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when category_id is null then 1 else 0 end) as uncategorized')
            ->groupBy('period')
            // Rows of aggregates, not models: read off the base query so they
            // stay the plain objects they are.
            ->toBase()
            ->get()
            ->keyBy('period');
    }

    /**
     * Closed months in a row, ending with the last one, in which everything
     * recorded got a category.
     *
     * Counted backwards from the last closed month rather than forwards from
     * the first: the run the next medal needs is the one still going, and a
     * broken run has to be rebuilt from scratch to earn anything. A month with
     * nothing recorded in it breaks the run, exactly as it does for the sweep.
     *
     * @param  Collection<string, object>  $months
     */
    private function categorizedRun(Collection $months): int
    {
        $month = now()->subMonth()->startOfMonth();
        $run = 0;

        while (true) {
            $row = $months->get($month->format('Y-m'));

            if ($row === null || (int) $row->total === 0 || (int) $row->uncategorized > 0) {
                return $run;
            }

            $run++;
            $month->subMonth();
        }
    }
}
