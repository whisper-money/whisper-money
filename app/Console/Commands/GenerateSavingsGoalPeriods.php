<?php

namespace App\Console\Commands;

use App\Models\SavingsGoal;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Console\Command;

class GenerateSavingsGoalPeriods extends Command
{
    protected $signature = 'savings-goals:generate-periods';

    protected $description = 'Open the current month of every monthly savings goal and close the months that are over';

    public function __construct(protected SavingsGoalPeriodService $periods)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $closedCount = 0;

        // Archived goals stopped counting, so they get no further months.
        $goals = SavingsGoal::query()->monthly()->notArchived()->with('user')->lazyById();

        foreach ($goals as $goal) {
            $closedCount += $this->periods->advance($goal)->count();
        }

        $this->info("Closed {$closedCount} monthly savings goal periods");

        return Command::SUCCESS;
    }
}
