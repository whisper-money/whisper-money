<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsBudgets;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s budgets with the current period\'s allocated, spent and remaining amounts, plus the categories and labels each one tracks. Covers the whole account.')]
class ListBudgets extends McpTool
{
    use PresentsBudgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        // ponytail: two extra queries per budget (its current period, and that
        // period's spend). Fine for the handful of budgets a user keeps; eager
        // load the current period with a sum if anyone ever runs dozens.
        $budgets = $user->budgets()
            ->notArchived()
            ->with(['categories:id,name', 'labels:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Budget $budget): array => $this->presentBudget($budget))
            ->all();

        return $this->json([
            'currency' => $user->currency_code ?? 'USD',
            'budgets' => $budgets,
        ]);
    }
}
