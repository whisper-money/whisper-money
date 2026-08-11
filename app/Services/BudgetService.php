<?php

namespace App\Services;

use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creating and editing a budget, shared by the web controller and the MCP tools
 * so both surfaces seed periods, backfill history and move the allocated amount
 * the same way.
 */
class BudgetService
{
    public function __construct(private readonly BudgetPeriodService $periods = new BudgetPeriodService) {}

    /**
     * Create a budget with its current and previous period, then backfill both
     * with the transactions already in range.
     *
     * @param  array<string, mixed>  $attributes  name, period_type, period_start_day, rollover_type, is_catch_all
     * @param  array<int, string>  $categoryIds
     * @param  array<int, string>  $labelIds
     */
    public function create(User $user, array $attributes, int $allocatedAmount, array $categoryIds = [], array $labelIds = []): Budget
    {
        [$budget, $period, $previousPeriod] = DB::transaction(function () use ($user, $attributes, $allocatedAmount, $categoryIds, $labelIds): array {
            $setting = $user->setting;

            $budget = $user->budgets()->create([
                ...$attributes,
                'notify_on_new_transaction' => $setting->budget_notify_on_new_transaction ?? false,
                'notify_on_close_to_limit' => $setting->budget_notify_on_close_to_limit ?? true,
                'notify_on_over_limit' => $setting->budget_notify_on_over_limit ?? true,
            ]);

            $budget->categories()->sync($categoryIds);
            $budget->labels()->sync($labelIds);

            $period = $this->periods->generatePeriod($budget, $allocatedAmount, null, true);

            return [$budget, $period, $this->periods->generatePreviousPeriod($budget, $period, $allocatedAmount, true)];
        });

        AssignHistoricalTransactionsToBudget::dispatch($budget, $period);
        AssignHistoricalTransactionsToBudget::dispatch($budget, $previousPeriod);

        return $budget;
    }

    /**
     * Edit a budget. A new allocated amount applies to the period in progress and
     * to every future one — editing the limit is meant to change what is left to
     * spend now, not only what the next period gets.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Budget $budget, array $attributes, ?int $allocatedAmount = null): Budget
    {
        DB::transaction(function () use ($budget, $attributes, $allocatedAmount): void {
            $budget->update($attributes);

            if ($allocatedAmount !== null) {
                $budget->periods()
                    ->where('end_date', '>=', today())
                    ->update(['allocated_amount' => $allocatedAmount]);
            }
        });

        return $budget;
    }
}
