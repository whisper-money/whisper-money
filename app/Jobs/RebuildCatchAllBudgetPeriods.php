<?php

namespace App\Jobs;

use App\Models\BudgetPeriod;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public string $userId,
        public string $from,
        public string $to,
    ) {}

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
     */
    private function rebuild(BudgetTransactionService $service, BudgetPeriod $period): void
    {
        if ($period->processing_historical) {
            $service->rebuildCatchAllPeriod($period);

            return;
        }

        $period->update(['processing_historical' => true]);

        try {
            $service->rebuildCatchAllPeriod($period);
        } finally {
            $period->update(['processing_historical' => false]);
        }
    }
}
