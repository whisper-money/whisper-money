<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a budget, with its periods and their spending history. The transactions themselves are untouched — only the budget that was watching them goes away.')]
class DeleteBudget extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Id of the budget to delete. Call list_budgets to see valid ids.')->required(),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        // An archived budget can still be thrown away, it just cannot change.
        $budget = $this->budgetOfUser($request, $user, allowArchived: true);

        $budget->delete();

        return $this->json(['deleted' => true, 'id' => $budget->id]);
    }
}
