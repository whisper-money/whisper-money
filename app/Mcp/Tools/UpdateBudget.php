<?php

namespace App\Mcp\Tools;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Mcp\Tools\Concerns\PresentsBudgets;
use App\Models\Budget;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a budget. Only the fields you pass are changed. A new allocated_amount applies to the period in progress and every future one. The categories and labels a budget tracks cannot be changed here — create a new budget for a different selection.')]
class UpdateBudget extends WriteTool
{
    use PresentsBudgets;

    /**
     * The editable fields, in the order the schema declares them.
     *
     * @var array<int, string>
     */
    private const FIELDS = ['name', 'period_type', 'period_start_day', 'rollover_type'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Id of the budget to edit. Call list_budgets to see valid ids.')->required(),
            'name' => $schema->string()->description('New budget name.'),
            'allocated_amount' => $schema->integer()->description('New limit per period, in minor units (50000 = 500.00). Applies to the current and future periods.'),
            'period_type' => $schema->string()->enum(array_column(BudgetPeriodType::cases(), 'value'))->description('New period length. Takes effect on the next period.'),
            'rollover_type' => $schema->string()->enum(array_column(RolloverType::cases(), 'value'))->description('New rollover behaviour.'),
            'period_start_day' => $schema->integer()->min(0)->max(31)->description('New day the period starts. Takes effect on the next period.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
            'period_type' => ['sometimes', Rule::enum(BudgetPeriodType::class)],
            'rollover_type' => ['sometimes', Rule::enum(RolloverType::class)],
            'period_start_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:31'],
        ]);

        $budget = $this->budgetOf($request, $user);

        $attributes = [];

        foreach (self::FIELDS as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->get($field);
            }
        }

        (new BudgetService)->update(
            $budget,
            $attributes,
            $request->has('allocated_amount') ? $request->integer('allocated_amount') : null,
        );

        return $this->json(['budget' => $this->presentBudget($budget->refresh())]);
    }

    /**
     * Budgets are owned by the user rather than scoped to a space, matching how
     * the app assigns transactions to them.
     */
    private function budgetOf(Request $request, User $user): Budget
    {
        $id = $request->string('budget_id')->toString();

        $budget = $user->budgets()->whereKey($id)->first();

        if ($budget === null) {
            throw ValidationException::withMessages([
                'budget_id' => "No budget with id {$id}. Call list_budgets to see valid ids.",
            ]);
        }

        return $budget;
    }
}
