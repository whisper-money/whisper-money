<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Get the three budgets frozen by a start day of 0 moving again cleanly.
     *
     * The service fix that ships with this migration floors the day at 1, which
     * is enough for the chain to resume - but not cleanly. Their last period ends
     * on 2026-07-29, so the next generated one starts on 1 July and overlaps it
     * by 29 days. Nothing re-buckets transactions between periods
     * (`AssignHistoricalTransactionsToBudget` is only dispatched when a budget is
     * created), so the new July would read as nothing spent while the old row
     * holds the rows, and `BudgetController::show` reaches neither: its previous
     * and next queries are strict comparisons that step straight over an
     * overlapping period.
     *
     * Closing the last period at the end of its month is all it takes for the
     * next one to start on the 1st and land contiguously. Two days of window are
     * added to a period that has already ended; assignment happens when a
     * transaction is written, so nothing is retroactively re-counted.
     *
     * The stored 0 becomes 1 as well. The floor makes it harmless, but the edit
     * dialog has been displaying these budgets as day 1 all along
     * (`period_start_day || 1`) and the MCP tools hand the raw column to agents,
     * so leaving it disagreeing with both is a trap for the next reader.
     */
    public function up(): void
    {
        Budget::query()
            ->where('period_type', BudgetPeriodType::Monthly)
            ->where('period_start_day', 0)
            ->each(function (Budget $budget): void {
                $lastPeriod = $budget->periods()->orderByDesc('end_date')->first();

                $lastPeriod?->update([
                    'end_date' => $lastPeriod->end_date->copy()->endOfMonth(),
                ]);

                $budget->update(['period_start_day' => 1]);
            });
    }

    public function down(): void
    {
        // One-off repair. Putting the 0 back would re-freeze the chain, and the
        // two days of window it closed carry nothing worth restoring.
    }
};
