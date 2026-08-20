<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Services\BudgetPeriodService;
use Illuminate\Console\Command;

class GenerateBudgetPeriods extends Command
{
    protected $signature = 'budgets:generate-periods';

    protected $description = 'Generate upcoming budget periods and close completed ones';

    public function __construct(protected BudgetPeriodService $budgetPeriodService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Generating budget periods...');

        // Not eager loading the periods: everything below re-queries them, and
        // loading every period of every budget is what eventually exhausted the
        // 128M limit and killed this command outright.
        $budgets = Budget::query()->get();
        $generatedCount = 0;
        $closedCount = 0;

        foreach ($budgets as $budget) {
            $currentPeriod = $budget->getCurrentPeriod();

            if (! $currentPeriod) {
                // Anchored on today, not on the end of the chain: a budget whose
                // last period ended months ago needs the period covering now, or
                // it crawls forward one period per night before it works again.
                $this->budgetPeriodService->generatePeriod($budget, null, today());
                $generatedCount++;
                $this->info("Generated initial period for budget: {$budget->name}");
            }

            // Oldest first, so that when two ended periods share a successor
            // the most recent one has the last word instead of whichever row
            // MySQL happened to return first.
            $completedPeriods = BudgetPeriod::where('budget_id', $budget->id)
                ->where('end_date', '<', today())
                ->orderBy('start_date')
                ->get();

            foreach ($completedPeriods as $period) {
                $this->budgetPeriodService->closePeriod($period);
                $closedCount++;
            }

            $futurePeriods = $budget->periods()
                ->where('start_date', '>', today())
                ->count();

            if ($futurePeriods < 2) {
                $this->budgetPeriodService->generatePeriod($budget);
                $generatedCount++;
            }
        }

        $this->info("Generated {$generatedCount} new periods");
        $this->info("Closed {$closedCount} completed periods");

        return Command::SUCCESS;
    }
}
