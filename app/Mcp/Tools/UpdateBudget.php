<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsBudgets;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Rename a budget or change its limit; only the fields you pass are changed. Its period length, start day, rollover and tracked categories are fixed — delete and recreate it to change those.')]
class UpdateBudget extends WriteTool
{
    use PresentsBudgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Id of the budget to edit. Call list_budgets to see valid ids.')->required(),
            'name' => $schema->string()->description('New budget name.'),
            'allocated_amount' => $schema->integer()->description('New limit per period, in the user currency\'s minor units. Applies to the period in progress and every future one.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(BudgetService::updateRules());

        $budget = $this->budgetOfUser($request, $user);

        app(BudgetService::class)->update(
            $budget,
            $request->has('name') ? ['name' => $request->string('name')->toString()] : [],
            $request->has('allocated_amount') ? $request->integer('allocated_amount') : null,
        );

        return $this->json(['budget' => $this->presentBudget($budget->refresh())]);
    }
}
