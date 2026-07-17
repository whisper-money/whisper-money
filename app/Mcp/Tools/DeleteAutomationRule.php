<?php

namespace App\Mcp\Tools;

use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete an automation rule. Transactions it already categorized keep their category; the rule simply stops running on future transactions.')]
class DeleteAutomationRule extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_rule_id' => $schema->string()->description('Id of the automation rule to delete.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);

        $id = $request->string('automation_rule_id')->toString();

        $rule = AutomationRule::query()->forSpace($space)->whereKey($id)->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'automation_rule_id' => "No automation rule with id {$id} in space {$space->id}.",
            ]);
        }

        $rule->delete();

        return $this->json(['deleted' => true, 'id' => $rule->id]);
    }
}
