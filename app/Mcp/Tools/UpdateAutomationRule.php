<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DecodesRulesJson;
use App\Models\AutomationRule;
use App\Models\Space;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Edit an automation rule. Only the fields you pass are changed. The rule must always keep at least one action (a category or labels). See create_automation_rule for the rules_json format.')]
class UpdateAutomationRule extends WriteTool
{
    use DecodesRulesJson;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_rule_id' => $schema->string()->description('Id of the automation rule to edit.')->required(),
            'title' => $schema->string()->description('New rule name.'),
            'priority' => $schema->integer()->min(0)->description('New priority (lower is evaluated first).'),
            'rules_json' => $schema->object()->description('New JsonLogic condition object.'),
            'action_category_id' => $schema->string()->description('New category id to assign, or null to clear.'),
            'action_label_ids' => $schema->array()->items($schema->string())->description('Replacement set of label ids (replaces all existing labels).'),
            'action_note' => $schema->string()->description('New note to append, or null to clear.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'action_note' => ['sometimes', 'nullable', 'string'],
        ]);

        $space = $this->resolveSpace($request, $user);
        $rule = $this->ruleInSpace($request, $space);

        if ($request->has('title')) {
            $rule->title = $request->string('title')->toString();
        }
        if ($request->has('priority')) {
            $rule->priority = $request->integer('priority');
        }
        if ($request->has('rules_json')) {
            $rule->rules_json = $this->rulesJson($request);
        }
        if ($request->has('action_category_id')) {
            $rule->action_category_id = $request->filled('action_category_id')
                ? $this->categoryInSpace($request, $space, 'action_category_id')->id
                : null;
        }
        if ($request->has('action_note')) {
            $rule->action_note = $request->filled('action_note') ? $request->string('action_note')->toString() : null;
        }

        $newLabels = $request->has('action_label_ids')
            ? $this->labelsInSpace($request, $space, 'action_label_ids')
            : null;

        // The rule must keep at least one action. Compare against the labels it
        // would have after this edit (the new set if provided, else current).
        $labelCount = $newLabels !== null ? $newLabels->count() : $rule->labels()->count();

        if ($rule->action_category_id === null && $labelCount === 0) {
            throw ValidationException::withMessages([
                'action_category_id' => 'An automation rule must keep at least one action: a category or labels.',
            ]);
        }

        $rule->save();

        if ($newLabels !== null) {
            $rule->labels()->sync($newLabels->pluck('id')->all());
        }

        $rule->touch();

        return $this->json(['automation_rule' => $this->presentAutomationRule($rule->refresh())]);
    }

    private function ruleInSpace(Request $request, Space $space): AutomationRule
    {
        $id = $request->string('automation_rule_id')->toString();

        $rule = AutomationRule::query()->forSpace($space)->whereKey($id)->first();

        if ($rule === null) {
            throw ValidationException::withMessages([
                'automation_rule_id' => "No automation rule with id {$id} in space {$space->id}.",
            ]);
        }

        return $rule;
    }
}
