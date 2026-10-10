<?php

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Enums\MonthlyTargetType;
use App\Enums\SavingsGoalKind;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Mcp\Tools\Concerns\PresentsSavingsGoals;
use App\Mcp\Tools\Concerns\ValidatesSavingsGoalWrites;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a savings goal: one-off (save a total, optionally by a date) or monthly (save a fixed amount or a share of income every month). Contributions are transactions tagged with its label.')]
class CreateSavingsGoal extends WriteTool
{
    use PresentsSavingsGoals, ValidatesMonthlySavingsTarget, ValidatesSavingsGoalWrites;

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
            'auto_tag_account_id' => $schema->string()->description('Monthly only: a savings account whose incoming transfers are tagged to this goal by a new automation rule, including the ones since the start of this month. Call list_accounts for ids.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $monthly = $request->string('kind')->toString() === SavingsGoalKind::Monthly->value;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(SavingsGoalKind::class)],
            ...($monthly ? [
                ...$this->monthlyTargetRules(creating: true),
                'auto_tag_account_id' => ['nullable', 'string'],
            ] : [
                'target_amount' => ['required', 'integer', 'min:1'],
                'initial_amount' => ['nullable', 'integer', 'min:0'],
                'target_date' => ['nullable', 'date_format:Y-m-d', 'after:today', 'before_or_equal:2100-01-01'],
            ]),
        ]);

        $this->assertSavingsGoalNameIsFree($user, $validated['name']);

        if (filled($validated['auto_tag_account_id'] ?? null)) {
            $this->assertSavingsAccount($user, $validated['auto_tag_account_id']);
        }

        $goal = app(SavingsGoalService::class)->createFromInput($user, $validated);

        return $this->json(['savings_goal' => $this->presentSavingsGoal($goal->refresh())]);
    }

    /**
     * Only a savings account: on any other type an incoming transfer would
     * count against the goal rather than towards it.
     */
    private function assertSavingsAccount(User $user, string $accountId): void
    {
        if (! $user->accounts()->whereKey($accountId)->where('type', AccountType::Savings->value)->exists()) {
            throw ValidationException::withMessages([
                'auto_tag_account_id' => "No savings account of yours with id {$accountId}. Call list_accounts and pick one of type savings.",
            ]);
        }
    }
}
