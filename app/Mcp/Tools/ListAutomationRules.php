<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsAutomationRules;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s automation rules in a space, lowest priority number first. Use the ids with update_automation_rule, delete_automation_rule and apply_automation_rule.')]
class ListAutomationRules extends McpTool
{
    use PresentsAutomationRules;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);

        $rules = AutomationRule::query()
            ->forSpace($space)
            ->with('labels:id,name')
            ->orderBy('priority')
            ->orderBy('title')
            ->get()
            ->map(fn (AutomationRule $rule): array => $this->presentAutomationRule($rule))
            ->all();

        return $this->json([
            'space_id' => $space->id,
            'automation_rules' => $rules,
        ]);
    }
}
