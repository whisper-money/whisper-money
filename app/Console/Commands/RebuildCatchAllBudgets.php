<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Console\Command;

class RebuildCatchAllBudgets extends Command
{
    protected $signature = 'budgets:rebuild-catch-all
        {--user= : Only rebuild the catch-all budgets of the user with this email address}
        {--dry-run : Report what would change without making changes}';

    protected $description = 'Re-derive every period of the running catch-all budgets, which used to keep the expenses a budget created, deleted or archived later should have taken or given back, after dropping what archived budgets still hold from their archive day on';

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

        $verb = $isDryRun ? 'would be' : 'were';

        // First, so the catch-all rebuild below picks up what they let go of.
        $releasedFromArchived = $this->releaseArchivedBudgets($service, $userId, $isDryRun);

        $this->info("{$releasedFromArchived} transaction(s) dated from an archive day on {$verb} dropped from archived budgets.");

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

        $this->info("{$checked} catch-all period(s) checked: {$released} transaction(s) {$verb} released, {$absorbed} {$verb} absorbed.");

        return self::SUCCESS;
    }

    /**
     * Archiving used to leave a budget holding what was dated from its archive
     * day on, which the catch-all now takes over.
     */
    private function releaseArchivedBudgets(BudgetTransactionService $service, ?string $userId, bool $isDryRun): int
    {
        $budgets = Budget::query()
            ->whereNotNull('archived_at')
            ->where('is_catch_all', false)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->lazyById();

        $released = 0;

        foreach ($budgets as $budget) {
            $released += $isDryRun ? $service->countFromArchiveDay($budget) : $service->releaseFromArchiveDay($budget);
        }

        return $released;
    }
}
