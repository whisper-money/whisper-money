<?php

namespace App\Jobs;

use App\Models\BudgetPeriod;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Re-derive a user's catch-all periods over a stretch of time after what the
 * other budgets claim there changed: a budget created, deleted or archived.
 *
 * The catch-all keeps the rows it absorbed until something re-derives them, so
 * without this a new budget would count those expenses alongside it, and a
 * deleted or archived one would leave them counted nowhere.
 */
class RebuildCatchAllBudgetPeriods implements ShouldQueue
{
    use Queueable;

    /**
     * Waiting behind another rebuild of the same user releases the job without
     * failing it, which still spends a try: retried for a while instead, and
     * failed after three genuine exceptions.
     */
    public int $maxExceptions = 3;

    public int $backoff = 10;

    /**
     * On the backfills' queue: the catch-all page spins while this runs too.
     */
    public function __construct(
        public string $userId,
        public string $from,
        public string $to,
    ) {
        $this->onQueue(AssignHistoricalTransactionsToBudget::QUEUE);
    }

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(15);
    }

    /**
     * One rebuild per user at a time: two interleaving would each release and
     * backfill the same periods, and clear the processing flag under the other.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->userId))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(BudgetTransactionService $service): void
    {
        BudgetPeriod::query()
            ->whereHas('budget', fn ($query) => $query
                ->whereNull('archived_at')
                ->where('user_id', $this->userId)
                ->where('is_catch_all', true))
            ->overlapping(Carbon::parse($this->from), Carbon::parse($this->to))
            ->get()
            ->each(fn (BudgetPeriod $period) => $this->rebuild($service, $period));
    }

    /**
     * Flagged as processing while it runs, like a backfill, unless the
     * period's own backfill already holds the flag and will clear it itself.
     * Taken with a conditional update, so the flag is only cleared by whoever set it.
     */
    private function rebuild(BudgetTransactionService $service, BudgetPeriod $period): void
    {
        $holdsFlag = BudgetPeriod::query()
            ->whereKey($period->id)
            ->where('processing_historical', false)
            ->update(['processing_historical' => true]) === 1;

        try {
            $service->rebuildCatchAllPeriod($period);
        } finally {
            if ($holdsFlag) {
                BudgetPeriod::query()->whereKey($period->id)->update(['processing_historical' => false]);
            }
        }
    }
}
