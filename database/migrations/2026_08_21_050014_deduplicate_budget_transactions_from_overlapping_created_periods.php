<?php

use App\Models\BudgetTransaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drop the second copy of a spend that two overlapping periods both claim.
     *
     * Until the fix that ships with this migration, `generatePreviousPeriod`
     * derived a biweekly budget's previous period from the day before the
     * current one started, which lands mid-fortnight - so budget creation
     * produced two periods overlapping by a week and handed both to
     * `AssignHistoricalTransactionsToBudget`. Anything spent in the shared week
     * got a row against each. Production: 12 of the 13 live biweekly budgets,
     * 16 transactions across 8 budgets.
     *
     * Only the pair born in the same `BudgetService::create` call is touched,
     * matched on the two periods sharing a `created_at`. That is what separates
     * this bug's spurious period from a genuine mid-chain overlap left by the
     * old `period_start_day = 0` monthly bug, where the earlier row is real
     * history with real transactions - one such row exists in production and
     * must survive. If the two inserts happened to straddle a second boundary
     * the pair is simply skipped, which is the safe direction.
     *
     * The row deleted is always the one on the earlier-starting period: the
     * window the fixed algorithm would never produce, and the one the UI cannot
     * reach. The periods themselves are left alone - the spurious one also holds
     * genuine history from before the budget existed, and deleting it would
     * cascade that away.
     */
    public function up(): void
    {
        $this->pairsBornTogether()->each(function (object $pair): void {
            $keptTransactionIds = BudgetTransaction::query()
                ->where('budget_period_id', $pair->kept_period_id)
                ->pluck('transaction_id');

            BudgetTransaction::query()
                ->where('budget_period_id', $pair->spurious_period_id)
                ->whereIn('transaction_id', $keptTransactionIds)
                ->delete();
        });
    }

    public function down(): void
    {
        // One-off repair. The rows it removes were written by a bug and say
        // nothing the surviving row does not.
    }

    /**
     * Overlapping period pairs created in the same breath, spurious one first.
     *
     * @return Collection<int, object>
     */
    private function pairsBornTogether(): Collection
    {
        return DB::table('budget_periods as spurious')
            ->join('budget_periods as kept', function ($join): void {
                $join->on('kept.budget_id', '=', 'spurious.budget_id')
                    ->whereColumn('kept.id', '!=', 'spurious.id')
                    ->whereColumn('kept.start_date', '>', 'spurious.start_date')
                    ->whereColumn('kept.start_date', '<=', 'spurious.end_date')
                    ->whereColumn('kept.created_at', '=', 'spurious.created_at');
            })
            ->select('spurious.id as spurious_period_id', 'kept.id as kept_period_id')
            ->get();
    }
};
