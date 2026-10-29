<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DecodesRulesJson;
use App\Mcp\Tools\Concerns\PresentsAutomationRules;
use App\Models\User;
use App\Services\AutomationRules\AutomationRuleWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create an automation rule that auto-applies a category and/or labels to matching transactions. At least one action (action_category_id or action_label_ids) is required; see rules_json for its format.')]
class CreateAutomationRule extends WriteTool
{
    use DecodesRulesJson, PresentsAutomationRules;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Human-readable rule name.')->required(),
            'priority' => $schema->integer()->min(0)->description('Lower numbers are evaluated first.')->required(),
            'rules_json' => $schema->object()->description($this->rulesJsonDescription())->required(),
            'action_category_id' => $schema->string()->description('Category id to assign to matching transactions.'),
            'action_label_ids' => $schema->array()->items($schema->string())->description('Label ids to attach to matching transactions.'),
            'action_note' => $schema->string()->description('Note to append to matching transactions.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(AutomationRuleWriter::createRules());

        $space = $this->resolveSpace($request, $user);

        $rulesJson = $this->rulesJson($request);
        $labels = $this->labelsInSpace($request, $space, 'action_label_ids');
        $categoryId = $request->filled('action_category_id')
            ? $this->categoryInSpace($request, $space, 'action_category_id')->id
            : null;

        $rule = app(AutomationRuleWriter::class)->create($user, $space, [
            'title' => $request->string('title')->toString(),
            'priority' => $request->integer('priority'),
            'rules_json' => $rulesJson,
            'action_category_id' => $categoryId,
            'action_note' => $this->nullableString($request, 'action_note'),
        ], $labels->pluck('id')->all());

        return $this->json(['automation_rule' => $this->presentAutomationRule($rule)]);
    }
}
