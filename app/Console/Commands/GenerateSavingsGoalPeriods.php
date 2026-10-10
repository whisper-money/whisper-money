<?php

namespace App\Console\Commands;

use App\Models\SavingsGoal;
use App\Services\SavingsGoals\MonthlySavingsGoalNotifier;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Console\Command;
use Throwable;

class GenerateSavingsGoalPeriods extends Command
{
    protected $signature = 'savings-goals:generate-periods';

    protected $description = 'Open the current month of every monthly savings goal, close the months that are over and send their notices';

    public function __construct(
        protected SavingsGoalPeriodService $periods,
        protected MonthlySavingsGoalNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $closedCount = 0;

        // Archived goals stopped counting, so they get no further months.
        $goals = SavingsGoal::query()->monthly()->notArchived()->with('user')->lazyById();

        foreach ($goals as $goal) {
            // One goal that fails must not hold back everybody else's months.
            try {
                $closedCount += $this->periods->advance($goal)->count();
            } catch (Throwable $exception) {
                report($exception);
            }

            // Apart from the close: a notice that fails is retried tomorrow,
            // and must not keep the goal's months from moving on.
            try {
                $this->notifier->notify($goal);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Closed {$closedCount} monthly savings goal periods");

        return Command::SUCCESS;
    }
}
