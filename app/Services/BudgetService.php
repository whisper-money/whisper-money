<?php

namespace App\Services;

use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Jobs\RebuildCatchAllBudgetPeriods;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Creating and editing a budget, shared by the web controller, the MCP tools
 * and `/api/v1/budgets` so every surface seeds periods, backfills history and
 * moves the allocated amount the same way. The MCP tools and the API also
 * validate with the rules and refusals here.
 */
class BudgetService
{
    public function __construct(
        private readonly BudgetPeriodService $periods,
        private readonly BudgetTransactionService $transactions,
    ) {}

    /**
     * The plain fields of a new budget. The categories and labels it tracks
     * are each caller's to check, naming the valid ids its own way.
     *
     * @param  BudgetPeriodType|null  $periodType  the period sent, which sets the range of `period_start_day`; null while it is not a valid one
     * @return array<string, array<mixed>>
     */
    public static function createRules(?BudgetPeriodType $periodType): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'allocated_amount' => ['required', 'integer', 'min:0'],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            // The field means a day of the week for weekly periods and a day of
            // the month for the rest, so its range depends on period_type.
            'period_start_day' => ['sometimes', 'nullable', 'integer', ...($periodType?->startDayRules() ?? [])],
            'is_catch_all' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What an edit may change. The period length, start day, rollover and
     * tracked categories stay as created: budgets are calculated historically,
     * so reshaping their periods afterwards would leave overlapping ones
     * counting the same transaction twice. The web edit dialog locks these
     * fields for the same reason.
     *
     * @return array<string, array<mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Why a new budget cannot track what it was given, keyed by field. A
     * budget needs something to watch: either explicit categories or labels,
     * or the single catch-all budget that absorbs whatever the others leave
     * behind.
     *
     * The web form checks the same two rules (StoreBudgetRequest), with
     * wording aimed at someone looking at the create dialog.
     *
     * @param  array<int, string>  $categoryIds
     * @param  array<int, string>  $labelIds
     * @return array<string, string>
     */
    public static function trackingRefusals(User $user, bool $isCatchAll, array $categoryIds, array $labelIds): array
    {
        if (! $isCatchAll && $categoryIds === [] && $labelIds === []) {
            return ['category_ids' => 'Pass at least one category or label to track, or set is_catch_all to true.'];
        }

        if ($isCatchAll && $user->budgets()->notArchived()->where('is_catch_all', true)->exists()) {
            return ['is_catch_all' => 'This account already has a catch-all budget.'];
        }

        return [];
    }

    /**
     * Create a budget with its current and previous period, then backfill both
     * with the transactions already in range.
     *
     * @param  array<string, mixed>  $attributes  name, period_type, period_start_day, rollover_type, expense_type, is_catch_all
     * @param  array<int, string>  $categoryIds
     * @param  array<int, string>  $labelIds
     */
    public function create(User $user, array $attributes, int $allocatedAmount, array $categoryIds = [], array $labelIds = []): Budget
    {
        [$budget, $period, $previousPeriod] = DB::transaction(function () use ($user, $attributes, $allocatedAmount, $categoryIds, $labelIds): array {
            $setting = $user->setting;

            $budget = $user->budgets()->create([
                ...$this->keepCatchAllVariable($attributes, (bool) ($attributes['is_catch_all'] ?? false)),
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
        // Queued last, though the order does not matter: the claims it reads
        // were all written above.
        $this->rebuildCatchAllAround($budget);

        return $budget;
    }

    /**
     * Delete a budget; the catch-all takes back what it used to claim.
     */
    public function delete(Budget $budget): void
    {
        $budget->delete();

        $this->rebuildCatchAllAround($budget);
    }

    /**
     * Archive a budget: it stops counting from today on, and the catch-all
     * takes over from that day. What it counted before stays with it, see
     * {@see BudgetTransactionService::releaseFromArchiveDay()}.
     */
    public function archive(Budget $budget): void
    {
        DB::transaction(function () use ($budget): void {
            $budget->update(['archived_at' => now()]);

            if (! $budget->is_catch_all) {
                $this->transactions->releaseFromArchiveDay($budget);
            }
        });

        $this->rebuildCatchAllAround($budget);
    }

    /**
     * Re-derive the user's catch-all periods over the stretch a budget's
     * periods cover, once what that budget claims there has changed.
     */
    private function rebuildCatchAllAround(Budget $budget): void
    {
        if ($budget->is_catch_all) {
            return;
        }

        $from = $budget->periods()->min('start_date');
        $to = $budget->periods()->max('end_date');

        if ($from !== null && $to !== null) {
            RebuildCatchAllBudgetPeriods::dispatch($budget->user_id, (string) $from, (string) $to);
        }
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
            $budget->update($this->keepCatchAllVariable($attributes, $budget->is_catch_all));

            if ($allocatedAmount !== null) {
                $budget->periods()
                    ->where('end_date', '>=', today())
                    ->update(['allocated_amount' => $allocatedAmount]);
            }
        });

        return $budget;
    }

    /**
     * A catch-all budget takes whatever no other budget covers, so it has no
     * kind of spending of its own and keeps the default expense type whatever
     * the caller sends.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function keepCatchAllVariable(array $attributes, bool $isCatchAll): array
    {
        if ($isCatchAll && array_key_exists('expense_type', $attributes)) {
            $attributes['expense_type'] = BudgetExpenseType::Variable;
        }

        return $attributes;
    }
}
