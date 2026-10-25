<?php

namespace App\Jobs;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AssignHistoricalTransactionsToBudget implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Budget $budget,
        public BudgetPeriod $period
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BudgetTransactionService $service): void
    {
        Log::info('Starting historical transaction assignment', [
            'budget_id' => $this->budget->id,
            'budget_period_id' => $this->period->id,
            'period_start' => $this->period->start_date,
            'period_end' => $this->period->end_date,
            'user_id' => $this->budget->user_id,
        ]);

        $count = $service->assignHistoricalTransactionsToPeriod($this->period);

        // Mark processing as complete
        $this->period->update(['processing_historical' => false]);

        Log::info("Assigned {$count} historical transactions to budget period", [
            'budget_id' => $this->budget->id,
            'budget_period_id' => $this->period->id,
            'user_id' => $this->budget->user_id,
        ]);
    }
}
