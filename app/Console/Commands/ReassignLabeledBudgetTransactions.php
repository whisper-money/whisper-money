<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ReassignLabeledBudgetTransactions extends Command
{
    protected $signature = 'budgets:reassign-labeled
        {--user= : Filter by user email address}
        {--dry-run : Preview what would be reassigned without making changes}';

    protected $description = 'Re-derive every budget assignment of the labeled transactions sitting in a catch-all budget, which used to absorb them even when another budget tracked their label';

    public function handle(BudgetTransactionService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $userEmail = $this->option('user');
        $userId = null;

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        if ($userEmail) {
            $user = User::query()->where('email', $userEmail)->first();

            if (! $user) {
                $this->error("User with email '{$userEmail}' not found.");

                return self::FAILURE;
            }

            $userId = $user->id;
        }

        // Only labeled transactions currently absorbed by a catch-all budget can
        // be wrong. Reassignment is idempotent, so the ones no other budget
        // tracks are re-derived to exactly what they already are.
        $query = Transaction::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->whereHas('labels')
            ->whereHas('budgetTransactions.budgetPeriod.budget', fn ($q) => $q->where('is_catch_all', true));

        $checked = $query->count();

        if ($isDryRun) {
            $this->info("{$checked} labeled transaction(s) in a catch-all budget would be re-derived.");

            return self::SUCCESS;
        }

        // Silently: these are historical assignments, so a budget-limit email
        // would announce a threshold the user crossed weeks ago.
        $query->with('labels')->chunkById(200, function (Collection $transactions) use ($service): void {
            foreach ($transactions as $transaction) {
                $service->assignTransaction($transaction, notify: false);
            }
        });

        $moved = $checked - $query->count();

        $this->info("{$checked} labeled transaction(s) re-derived, {$moved} moved out of a catch-all budget.");

        return self::SUCCESS;
    }
}
