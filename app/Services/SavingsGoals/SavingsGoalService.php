<?php

namespace App\Services\SavingsGoals;

use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Enums\MonthlyTargetType;
use App\Enums\RuleOrigin;
use App\Enums\SavingsGoalKind;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\MonthlySavingsGoalClosed;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\DB;

/**
 * Creating, editing and retiring a savings goal, shared by the web controller
 * and the MCP tools so both surfaces create the label, open the first month and
 * carry an edited target the same way.
 */
class SavingsGoalService
{
    public function __construct(
        private SavingsGoalPeriodService $periods,
        private AutomationRuleService $rules,
    ) {}

    /**
     * Create a goal from validated input, in $space or the user's active one:
     * the goal, its label and its auto-tag rule all live there. A monthly goal
     * opens its first month and, given `auto_tag_account_id` (an account of
     * that space), a rule that tags transfers into that savings account.
     *
     * @param  array<string, mixed>  $input
     */
    public function createFromInput(User $user, array $input, ?Space $space = null): SavingsGoal
    {
        $space ??= $user->activeSpace();

        return DB::transaction(function () use ($user, $input, $space): SavingsGoal {
            if (($input['kind'] ?? null) !== SavingsGoalKind::Monthly->value) {
                return $this->create($user, $space, [
                    'name' => $input['name'],
                    'target_amount' => $input['target_amount'],
                    // Nullable input coerced to 0 cents: the column is NOT NULL.
                    'initial_amount' => (int) ($input['initial_amount'] ?? 0),
                    'target_date' => $input['target_date'] ?? null,
                ]);
            }

            $goal = $this->create($user, $space, $this->monthlyAttributes($input, $user->wantsSavingsGoalRemindersByDefault()));
            $this->periods->openPeriod($goal, today());

            if (filled($input['auto_tag_account_id'] ?? null)) {
                $this->createAutoTagRule($goal, Account::query()->forSpace($space)->findOrFail($input['auto_tag_account_id']));
            }

            return $goal;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(User $user, Space $space, array $attributes): SavingsGoal
    {
        $label = $user->labels()->create([
            'space_id' => $space->id,
            'name' => $attributes['name'],
            'color' => LabelColor::Emerald->value,
            'source' => LabelSource::SavingsGoal,
        ]);

        return $user->savingsGoals()->create([...$attributes, 'space_id' => $space->id, 'label_id' => $label->id]);
    }

    /**
     * A monthly goal's attributes, normalised: the one-off fields are zeroed and
     * only the value its target type uses is kept.
     *
     * @param  array<string, mixed>  $input
     * @param  bool  $remindByDefault  the user's default, for a caller that does not say
     * @return array<string, mixed>
     */
    private function monthlyAttributes(array $input, bool $remindByDefault): array
    {
        return [
            'name' => $input['name'],
            'kind' => SavingsGoalKind::Monthly,
            // A monthly goal has no total to reach; the column is not nullable.
            'target_amount' => 0,
            'initial_amount' => 0,
            'target_date' => null,
            ...self::monthlyTarget(MonthlyTargetType::from($input['monthly_target_type']), $input),
            'notify_on_month_end_reminder' => (bool) ($input['notify_on_month_end_reminder'] ?? $remindByDefault),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{monthly_target_type: MonthlyTargetType, monthly_target_amount: ?int, monthly_target_rate: ?float}
     */
    private static function monthlyTarget(MonthlyTargetType $type, array $input): array
    {
        return [
            'monthly_target_type' => $type,
            'monthly_target_amount' => $type === MonthlyTargetType::Amount ? (int) $input['monthly_target_amount'] : null,
            'monthly_target_rate' => $type === MonthlyTargetType::IncomeRate ? (float) $input['monthly_target_rate'] : null,
        ];
    }

    /**
     * Edit a goal. A monthly goal's new target applies to the month in progress
     * only: the months already over keep the target they were held to.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(SavingsGoal $goal, array $input): SavingsGoal
    {
        DB::transaction(function () use ($goal, $input): void {
            $goal->update($goal->isMonthly() ? $this->monthlyChanges($input) : array_intersect_key($input, array_flip(['name', 'target_amount', 'initial_amount', 'target_date'])));

            if (array_key_exists('name', $input)) {
                $goal->label?->update(['name' => $input['name']]);
            }

            if ($goal->isMonthly() && $goal->wasChanged(['monthly_target_type', 'monthly_target_amount', 'monthly_target_rate'])) {
                $this->periods->syncCurrentPeriod($goal);
            }
        });

        return $goal;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function monthlyChanges(array $input): array
    {
        $changes = array_intersect_key($input, array_flip(['name', 'notify_on_month_end_reminder']));

        if (! array_key_exists('monthly_target_type', $input)) {
            return $changes;
        }

        return [...$changes, ...self::monthlyTarget(MonthlyTargetType::from($input['monthly_target_type']), $input)];
    }

    /**
     * Archiving is one-way and freezes the goal.
     *
     * Its label goes with it — the goal is done, so the label must never be
     * pickable again, and soft-deleting it takes it out of every picker at once
     * through the global scope. That also means the saved amount can no longer
     * be derived (the sum would collapse to the starting balance, and re-tagging
     * one of those transactions later would move a final figure), so it is
     * snapshotted here. A monthly goal also closes its open months, so a
     * share-of-income target stops following the income that comes after.
     * Everything shares one transaction: a goal that is half-archived has no
     * meaning.
     */
    public function archive(SavingsGoal $goal): void
    {
        DB::transaction(function () use ($goal): void {
            // Read before the archive date is written: savedAmountInCents()
            // switches to the snapshot the moment the goal counts as archived.
            $saved = $goal->savedAmountInCents();

            $goal->update([
                'archived_at' => now(),
                'archived_saved_amount' => $saved,
            ]);

            if ($goal->isMonthly()) {
                // Persist any month a read only showed, so the archive month
                // exists and is closed with the rest.
                $this->periods->advance($goal);
                $this->periods->closeOpenPeriods($goal);
            }

            $this->retireLabel($goal);
        });
    }

    public function delete(SavingsGoal $goal): void
    {
        DB::transaction(function () use ($goal): void {
            $this->retireLabel($goal);
            // Its bell rows would lead nowhere.
            $goal->user->notifications()
                ->where('type', MonthlySavingsGoalClosed::class)
                ->where('data->savings_goal_id', $goal->id)
                ->delete();
            $goal->delete();
        });
    }

    /**
     * Soft-delete the goal's label and take it off every automation rule. A rule
     * that did nothing but tag the goal — the one a monthly goal can create —
     * goes too, rather than staying behind in the rules list doing nothing.
     */
    private function retireLabel(SavingsGoal $goal): void
    {
        if ($goal->label_id === null) {
            return;
        }

        AutomationRule::query()
            ->where('user_id', $goal->user_id)
            ->whereHas('labels', fn ($query) => $query->whereKey($goal->label_id))
            ->with('labels')
            ->get()
            ->each(function (AutomationRule $rule) use ($goal): void {
                $rule->labels()->detach($goal->label_id);

                if ($rule->labels->count() === 1 && $rule->action_category_id === null && blank($rule->action_note)) {
                    $rule->delete();
                }
            });

        $goal->label?->delete();
    }

    /**
     * Tag every transfer that lands in $account with the goal's label from now
     * on, and the ones already in it since the goal's first month that no
     * other goal counts, so the month in progress starts with what was
     * already moved.
     *
     * Rules can only tell accounts apart by name, so the bank's name is matched
     * too: a checking account somewhere else that happens to share the name
     * would otherwise count its income as a contribution.
     *
     * The rule goes last in the user's order. Rules stop at the first match,
     * and putting this one first would take those transfers away from a rule
     * that already categorizes them, leaving them uncategorized income. When
     * such a rule exists the transfers have to be linked from the goal's page.
     */
    private function createAutoTagRule(SavingsGoal $goal, Account $account): AutomationRule
    {
        $account->loadMissing('bank');

        $rule = $goal->user->automationRules()->create([
            'space_id' => $goal->space_id,
            'title' => __('Contributions to :goal', ['goal' => $goal->name]),
            'priority' => (int) $goal->user->automationRules()->max('priority') + 1,
            'origin' => RuleOrigin::User->value,
            'rules_json' => ['and' => array_values(array_filter([
                ['==' => [['var' => 'account_name'], mb_strtolower(trim($account->name))]],
                $account->bank ? ['==' => [['var' => 'bank_name'], mb_strtolower($account->bank->name)]] : null,
                ['>' => [['var' => 'amount'], 0]],
            ]))],
        ]);

        $rule->labels()->sync([$goal->label_id]);
        $goal->update(['auto_tag_account_id' => $account->id]);

        // A transfer already counting for another goal stays with it: one euro
        // set aside cannot fill two goals.
        $existing = Transaction::query()
            ->where('account_id', $account->id)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $goal->created_at->copy()->startOfMonth()->toDateString())
            ->whereDoesntHave('labels', fn ($query) => $query->where('source', LabelSource::SavingsGoal->value))
            ->get()
            ->filter(fn (Transaction $transaction): bool => $this->rules->ruleMatches($rule, $transaction));

        $this->rules->applyRuleActionsToTransactions($existing, $rule);

        return $rule;
    }
}
