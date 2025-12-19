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

        $budgets = Budget::with('periods')->get();
        $generatedCount = 0;
        $closedCount = 0;

        foreach ($budgets as $budget) {
            $currentPeriod = $budget->getCurrentPeriod();

            if (! $currentPeriod) {
                $this->budgetPeriodService->generatePeriod($budget);
                $generatedCount++;
                $this->info("Generated initial period for budget: {$budget->name}");
            }

            $completedPeriods = BudgetPeriod::where('budget_id', $budget->id)
                ->where('end_date', '<', now()->subDay())
                ->whereDoesntHave('allocations', function ($query) {
                    $query->whereHas('budgetTransactions');
                })
                ->get();

            foreach ($completedPeriods as $period) {
                if ($period->end_date < now()->subDay()) {
                    $this->budgetPeriodService->closePeriod($period);
                    $closedCount++;
                }
            }

            $futurePeriods = $budget->periods()
                ->where('start_date', '>', now())
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
