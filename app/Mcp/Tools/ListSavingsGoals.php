<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsSavingsGoals;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the savings goals of a space, archived ones included: one-off goals with their progress towards the total, monthly goals with the month in progress and every past month against its target.')]
class ListSavingsGoals extends McpTool
{
    use PresentsSavingsGoals;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        return $this->json([
            'currency' => $this->reportingCurrency($user),
            'savings_goals' => $this->presentSavingsGoals($this->resolveSpace($request, $user)),
        ]);
    }
}
