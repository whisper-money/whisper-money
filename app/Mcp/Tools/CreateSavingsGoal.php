<?php

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Enums\MonthlyTargetType;
use App\Enums\SavingsGoalKind;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesOneOffSavingsTarget;
use App\Mcp\Tools\Concerns\PresentsSavingsGoals;
use App\Mcp\Tools\Concerns\ValidatesSavingsGoalWrites;
use App\Models\AutomationRule;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a savings goal: one-off (a total, optionally by a date) or monthly (an amount or share of income each month). Monthly can auto-tag a savings account\'s past and future transfers.')]
class CreateSavingsGoal extends WriteTool
{
    use PresentsSavingsGoals, ValidatesMonthlySavingsTarget, ValidatesOneOffSavingsTarget, ValidatesSavingsGoalWrites;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Goal name. It also names the label its contributions are tagged with, so it must not clash with an existing label.')->required(),
            'kind' => $schema->string()->enum(array_column(SavingsGoalKind::cases(), 'value'))->description('"one_off" to reach a total once, "monthly" to save towards a target every calendar month. Fixed once created.')->required(),
            'target_amount' => $schema->integer()->description('One-off only, required: the total to reach, in the user currency\'s minor units.'),
            'initial_amount' => $schema->integer()->description('One-off only: what is already saved towards it, in minor units.'),
            'target_date' => $schema->string()->description('One-off only: date to reach the total by, YYYY-MM-DD, in the future.'),
            'monthly_target_type' => $schema->string()->enum(array_column(MonthlyTargetType::cases(), 'value'))->description('Monthly only, required: "amount" for a fixed sum each month, "income_rate" for a share of the average income of the 3 previous complete months.'),
            'monthly_target_amount' => $schema->integer()->description('Monthly with "amount": the sum to save each month, in minor units.'),
            'monthly_target_rate' => $schema->number()->description('Monthly with "income_rate": the percentage of income to save each month, above 0 and up to 100, two decimals at most.'),
            'notify_on_month_end_reminder' => $schema->boolean()->description('Monthly only: email a reminder 5 days before month end while the month is short of its target. Defaults to the user\'s setting.'),
            'auto_tag_account_id' => $schema->string()->description('Monthly only: a savings account of the same space whose incoming transfers are tagged to this goal by a new automation rule, including the ones since the start of this month. Call list_accounts for ids.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $kind = SavingsGoalKind::tryFrom($request->string('kind')->toString());
        [$otherKindRules, $messages] = $kind === null ? [[], []] : $this->otherKindFieldRules($kind);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(SavingsGoalKind::class)],
            ...$otherKindRules,
            ...($kind === SavingsGoalKind::Monthly ? [
                ...$this->monthlyTargetRules(creating: true),
                'auto_tag_account_id' => ['nullable', 'uuid'],
            ] : $this->oneOffTargetRules(creating: true)),
        ], $messages);

        $space = $this->resolveSpace($request, $user);
        $this->assertSavingsGoalNameIsFree($space, $validated['name']);

        $autoTagAccountId = $validated['auto_tag_account_id'] ?? null;

        if (filled($autoTagAccountId)) {
            $this->assertSavingsAccount($request, $space);
        }

        $goal = app(SavingsGoalService::class)->createFromInput($user, $validated, $space)->refresh();

        return $this->json([
            'currency' => $this->reportingCurrency($user),
            'savings_goal' => $this->presentSavingsGoal($goal),
            'auto_tag' => filled($autoTagAccountId) ? $this->autoTagOutcome($goal, $autoTagAccountId) : null,
        ]);
    }

    /**
     * What the auto-tag option did, so the agent can tell the user: the rule it
     * added (deletable with delete_automation_rule) and how many transfers
     * already in the account it tagged. Rules stop at the first match, so an
     * older rule matching the same transfers leaves this count at zero.
     *
     * @return array{rule_id: string|null, account_id: string, tagged_transactions: int}
     */
    private function autoTagOutcome(SavingsGoal $goal, string $accountId): array
    {
        return [
            'rule_id' => AutomationRule::query()
                ->where('user_id', $goal->user_id)
                ->whereHas('labels', fn ($query) => $query->whereKey($goal->label_id))
                ->value('id'),
            'account_id' => $accountId,
            'tagged_transactions' => $goal->label?->transactions()->count() ?? 0,
        ];
    }

    /**
     * An account of the goal's space, where its rule and label live, and only
     * a savings one: on any other type an incoming transfer would count
     * against the goal rather than towards it.
     */
    private function assertSavingsAccount(Request $request, Space $space): void
    {
        $account = $this->accountInSpace($request, $space, 'auto_tag_account_id');

        if ($account->type !== AccountType::Savings) {
            throw ValidationException::withMessages([
                'auto_tag_account_id' => "Account {$account->id} is not a savings account. Call list_accounts and pick one of type savings.",
            ]);
        }
    }
}
