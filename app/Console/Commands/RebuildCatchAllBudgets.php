<?php

namespace App\Console\Commands;

use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Console\Command;

class RebuildCatchAllBudgets extends Command
{
    protected $signature = 'budgets:rebuild-catch-all
        {--user= : Only rebuild the catch-all budgets of the user with this email address}
        {--dry-run : Report what would change without making changes}';

    protected $description = 'Re-derive every period of the running catch-all budgets, which used to keep the expenses a budget created, deleted or archived later should have taken or given back';

    public function handle(BudgetTransactionService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $userEmail = $this->option('user');
        $userId = null;

        if ($userEmail) {
            $userId = User::query()->where('email', $userEmail)->value('id');

            if ($userId === null) {
                $this->error("User with email '{$userEmail}' not found.");

                return self::FAILURE;
            }
        }

        // An archived catch-all keeps what it counted, like any archived budget.
        $periods = BudgetPeriod::query()
            ->whereHas('budget', fn ($query) => $query
                ->whereNull('archived_at')
                ->where('is_catch_all', true)
                ->when($userId !== null, fn ($q) => $q->where('user_id', $userId)))
            ->with('budget')
            ->lazyById();

        $released = 0;
        $absorbed = 0;
        $checked = 0;

        foreach ($periods as $period) {
            // Silent: rebuilding never sends budget emails.
            $changes = $isDryRun ? $service->catchAllDrift($period) : $service->rebuildCatchAllPeriod($period);

            $released += $changes['released'];
            $absorbed += $changes['absorbed'];
            $checked++;
        }

        $verb = $isDryRun ? 'would be' : 'were';

        $this->info("{$checked} catch-all period(s) checked: {$released} transaction(s) {$verb} released, {$absorbed} {$verb} absorbed.");

        return self::SUCCESS;
    }
}
