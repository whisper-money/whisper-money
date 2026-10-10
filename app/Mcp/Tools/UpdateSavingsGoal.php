<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Mcp\Tools\Concerns\PresentsSavingsGoals;
use App\Mcp\Tools\Concerns\ValidatesSavingsGoalWrites;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a savings goal; only the fields you pass change. Its kind is fixed. A monthly target change applies to the month in progress and later ones, never to past months.')]
class UpdateSavingsGoal extends WriteTool
{
    use PresentsSavingsGoals, ValidatesMonthlySavingsTarget, ValidatesSavingsGoalWrites;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'savings_goal_id' => $schema->string()->description('Id of the goal to edit. Call list_savings_goals to see valid ids.')->required(),
            'name' => $schema->string()->description('New name, which also renames its label.'),
            'target_amount' => $schema->integer()->description('One-off only: new total, in minor units.'),
            'initial_amount' => $schema->integer()->description('One-off only: new starting amount, in minor units.'),
            'target_date' => $schema->string()->description('One-off only: new target date, YYYY-MM-DD, or null to clear it.'),
            'monthly_target_type' => $schema->string()->description('Monthly only: "amount" or "income_rate". Required when changing the target amount or rate.'),
            'monthly_target_amount' => $schema->integer()->description('Monthly with "amount": new sum each month, in minor units.'),
            'monthly_target_rate' => $schema->number()->description('Monthly with "income_rate": new percentage of income, above 0 and up to 100.'),
            'notify_on_month_end_reminder' => $schema->boolean()->description('Monthly only: turn the month-end reminder email on or off.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $goal = $this->savingsGoalOfUser($request, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...($goal->isMonthly() ? $this->monthlyTargetRules(creating: false) : [
                'target_amount' => ['sometimes', 'required', 'integer', 'min:1'],
                'initial_amount' => ['sometimes', 'required', 'integer', 'min:0'],
                'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-01-01'],
            ]),
        ]);

        if (isset($validated['name'])) {
            $this->assertSavingsGoalNameIsFree($user, $validated['name'], $goal->label_id);
        }

        app(SavingsGoalService::class)->update($goal, $validated);

        return $this->json(['savings_goal' => $this->presentSavingsGoal($goal->refresh())]);
    }

    /**
     * An archived goal is frozen for good, so it is refused rather than edited.
     */
    private function savingsGoalOfUser(Request $request, User $user): SavingsGoal
    {
        $id = $request->string('savings_goal_id')->toString();
        $goal = $user->savingsGoals()->whereKey($id)->first();

        if ($goal === null) {
            throw ValidationException::withMessages([
                'savings_goal_id' => "No savings goal with id {$id}. Call list_savings_goals to see valid ids.",
            ]);
        }

        if ($goal->isArchived()) {
            throw ValidationException::withMessages([
                'savings_goal_id' => "Savings goal {$id} is archived and can no longer be changed.",
            ]);
        }

        return $goal;
    }
}
