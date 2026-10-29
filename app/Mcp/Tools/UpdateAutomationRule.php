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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Edit an automation rule. Only the fields you pass are changed. The rule must always keep at least one action (a category or labels). See create_automation_rule for the rules_json format.')]
class UpdateAutomationRule extends WriteTool
{
    use DecodesRulesJson, PresentsAutomationRules;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_rule_id' => $schema->string()->description('Id of the automation rule to edit.')->required(),
            'title' => $schema->string()->description('New rule name.'),
            'priority' => $schema->integer()->min(0)->description('New priority (lower is evaluated first).'),
            'rules_json' => $schema->object()->description('Replacement condition object (it replaces the rule\'s conditions entirely). '.$this->rulesJsonDescription()),
            'action_category_id' => $schema->string()->description('New category id to assign, or null to clear.'),
            'action_label_ids' => $schema->array()->items($schema->string())->description('Replacement set of label ids (replaces all existing labels).'),
            'action_note' => $schema->string()->description('New note to append, or null to clear.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(AutomationRuleWriter::updateRules());

        $space = $this->resolveSpace($request, $user);
        $rule = $this->ruleInSpace($request, $space);

        $changes = $this->requestedFields($request, [
            'title' => fn () => $request->string('title')->toString(),
            'priority' => fn () => $request->integer('priority'),
            'rules_json' => fn () => $this->rulesJson($request),
            'action_category_id' => fn () => $request->filled('action_category_id')
                ? $this->categoryInSpace($request, $space, 'action_category_id')->id
                : null,
            'action_note' => fn () => $this->nullableString($request, 'action_note'),
        ]);

        $labelIds = $request->has('action_label_ids')
            ? $this->labelsInSpace($request, $space, 'action_label_ids')->pluck('id')->all()
            : null;

        $rule = app(AutomationRuleWriter::class)->update($rule, $changes, $labelIds);

        return $this->json(['automation_rule' => $this->presentAutomationRule($rule)]);
    }
}
