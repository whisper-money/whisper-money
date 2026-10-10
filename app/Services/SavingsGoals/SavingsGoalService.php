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
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\DB;

/**
 * Creating and editing a savings goal, shared by the web controller and the MCP
 * tools so both surfaces create the label, open the first month and carry an
 * edited target the same way.
 */
class SavingsGoalService
{
    public function __construct(
        private SavingsGoalPeriodService $periods,
        private AutomationRuleService $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  name, plus target_amount/initial_amount/target_date for a one-off goal or the monthly_* fields for a monthly one
     */
    public function create(User $user, array $attributes): SavingsGoal
    {
        return DB::transaction(function () use ($user, $attributes): SavingsGoal {
            $label = $user->labels()->create([
                'name' => $attributes['name'],
                'color' => LabelColor::Emerald->value,
                'source' => LabelSource::SavingsGoal,
            ]);

            $goal = $user->savingsGoals()->create([...$attributes, 'label_id' => $label->id]);

            if ($goal->isMonthly()) {
                $this->periods->openPeriod($goal, today());
            }

            return $goal;
        });
    }

    /**
     * A monthly goal's attributes, normalised: the one-off fields are zeroed and
     * only the value its target type uses is kept.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function monthlyAttributes(array $input, bool $notifyByDefault = true): array
    {
        $type = MonthlyTargetType::from($input['monthly_target_type']);

        return [
            'name' => $input['name'],
            'kind' => SavingsGoalKind::Monthly,
            // A monthly goal has no total to reach; the column is not nullable.
            'target_amount' => 0,
            'initial_amount' => 0,
            'target_date' => null,
            ...self::monthlyTarget($type, $input),
            'notify_on_month_end_reminder' => (bool) ($input['notify_on_month_end_reminder'] ?? $notifyByDefault),
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
            $goal->update($goal->isMonthly() ? $this->monthlyChanges($goal, $input) : array_intersect_key($input, array_flip(['name', 'target_amount', 'initial_amount', 'target_date'])));

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
    private function monthlyChanges(SavingsGoal $goal, array $input): array
    {
        $changes = array_intersect_key($input, array_flip(['name', 'notify_on_month_end_reminder']));

        if (! array_key_exists('monthly_target_type', $input)) {
            return $changes;
        }

        return [...$changes, ...self::monthlyTarget(MonthlyTargetType::from($input['monthly_target_type']), $input)];
    }

    /**
     * Tag every transfer that lands in $account with the goal's label from now
     * on, and the ones already in it since the goal's first month, so the
     * month in progress starts with what was already moved.
     *
     * The rule goes last in the user's order: rules stop at the first match,
     * and one the user wrote for these same transfers should keep its say.
     */
    public function createAutoTagRule(SavingsGoal $goal, Account $account): AutomationRule
    {
        $rule = $goal->user->automationRules()->create([
            'title' => __('Contributions to :goal', ['goal' => $goal->name]),
            'priority' => (int) $goal->user->automationRules()->max('priority') + 1,
            'origin' => RuleOrigin::User->value,
            'rules_json' => ['and' => [
                ['==' => [['var' => 'account_name'], mb_strtolower(trim($account->name))]],
                ['>' => [['var' => 'amount'], 0]],
            ]],
        ]);

        $rule->labels()->sync([$goal->label_id]);

        $existing = Transaction::query()
            ->where('account_id', $account->id)
            ->where('amount', '>', 0)
            ->where('transaction_date', '>=', $goal->created_at->copy()->startOfMonth()->toDateString())
            ->get()
            ->filter(fn (Transaction $transaction): bool => $this->rules->ruleMatches($rule, $transaction));

        $this->rules->applyRuleActionsToTransactions($existing, $rule);

        return $rule;
    }
}
