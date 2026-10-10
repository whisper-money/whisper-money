<?php

namespace App\Mcp\Tools;

use App\Enums\MonthlyTargetType;
use App\Http\Requests\Concerns\ValidatesMonthlySavingsTarget;
use App\Http\Requests\Concerns\ValidatesOneOffSavingsTarget;
use App\Http\Requests\Concerns\ValidatesSavingsGoalName;
use App\Mcp\Tools\Concerns\PresentsSavingsGoals;
use App\Mcp\Tools\Concerns\ValidatesSavingsGoalWrites;
use App\Models\SavingsGoal;
use App\Models\Space;
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
    use PresentsSavingsGoals, ValidatesMonthlySavingsTarget, ValidatesOneOffSavingsTarget, ValidatesSavingsGoalName, ValidatesSavingsGoalWrites;

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
            'target_date' => $schema->string()->nullable()->description('One-off only: new target date, YYYY-MM-DD, or null to clear it.'),
            'monthly_target_type' => $schema->string()->enum(array_column(MonthlyTargetType::cases(), 'value'))->description('Monthly only. Send it with the matching value: "amount" with monthly_target_amount, "income_rate" with monthly_target_rate. Switching type clears the other value.'),
            'monthly_target_amount' => $schema->integer()->description('Monthly with "amount": new sum each month, in minor units.'),
            'monthly_target_rate' => $schema->number()->description('Monthly with "income_rate": new percentage of income, above 0 and up to 100.'),
            'notify_on_month_end_reminder' => $schema->boolean()->description('Monthly only: turn the month-end reminder email on or off.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $goal = $this->savingsGoalInSpace($request, $space);

        [$otherKindRules, $messages] = $this->otherKindFieldRules($goal->kind);

        $validated = $request->validate([
            'name' => $this->savingsGoalNameRules($user->id, creating: false, ownLabelId: $goal->label_id),
            ...$otherKindRules,
            ...($goal->isMonthly() ? $this->monthlyTargetRules(creating: false) : $this->oneOffTargetRules(creating: false)),
        ], $messages);

        app(SavingsGoalService::class)->update($goal, $validated);

        return $this->json([
            'currency' => $this->reportingCurrency($user),
            'savings_goal' => $this->presentSavingsGoal($goal->refresh()),
        ]);
    }

    /**
     * An archived goal is frozen for good, so it is refused rather than edited.
     */
    private function savingsGoalInSpace(Request $request, Space $space): SavingsGoal
    {
        $id = $request->string('savings_goal_id')->toString();
        $goal = SavingsGoal::query()->forSpace($space)->whereKey($id)->first();

        if ($goal === null) {
            throw ValidationException::withMessages([
                'savings_goal_id' => "No savings goal with id {$id} in space {$space->id}. Call list_savings_goals to see valid ids.",
            ]);
        }

        if ($goal->isArchived()) {
            throw ValidationException::withMessages([
                'savings_goal_id' => "Savings goal {$id} is archived and can no longer be changed. Archiving and deleting goals happen in the Whisper Money app.",
            ]);
        }

        return $goal;
    }
}
