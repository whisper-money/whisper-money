<?php

namespace App\Mcp\Tools;

use App\Enums\RuleOrigin;
use App\Mcp\Tools\Concerns\DecodesRulesJson;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description(<<<'TEXT'
Create an automation rule that auto-applies a category and/or labels to matching transactions. `rules_json` is a JsonLogic object evaluated against these lowercase variables: description, notes, creditor_name, debtor_name, account_name, bank_name, category, transaction_date (YYYY-MM-DD) and amount. Note: amount here is in MAJOR units (e.g. 12.50), not cents. Example: {"and":[{">":[{"var":"amount"},100]},{"in":["grocery",{"var":"description"}]}]}. At least one action (action_category_id or action_label_ids) is required.
TEXT)]
class CreateAutomationRule extends WriteTool
{
    use DecodesRulesJson;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Human-readable rule name.')->required(),
            'priority' => $schema->integer()->min(0)->description('Lower numbers are evaluated first.')->required(),
            'rules_json' => $schema->object()->description('JsonLogic condition object.')->required(),
            'action_category_id' => $schema->string()->description('Category id to assign to matching transactions.'),
            'action_label_ids' => $schema->array()->items($schema->string())->description('Label ids to attach to matching transactions.'),
            'action_note' => $schema->string()->description('Note to append to matching transactions.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'min:0'],
            'action_note' => ['sometimes', 'nullable', 'string'],
        ]);

        $space = $this->resolveSpace($request, $user);

        $rulesJson = $this->rulesJson($request);
        $labels = $this->labelsInSpace($request, $space, 'action_label_ids');
        $categoryId = $request->filled('action_category_id')
            ? $this->categoryInSpace($request, $space, 'action_category_id')->id
            : null;

        if ($categoryId === null && $labels->isEmpty()) {
            throw ValidationException::withMessages([
                'action_category_id' => 'At least one action is required: pass action_category_id and/or action_label_ids.',
            ]);
        }

        $rule = new AutomationRule([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'title' => $request->string('title')->toString(),
            'priority' => $request->integer('priority'),
            'origin' => RuleOrigin::User->value,
            'rules_json' => $rulesJson,
            'action_category_id' => $categoryId,
            'action_note' => $request->filled('action_note') ? $request->string('action_note')->toString() : null,
        ]);
        $rule->save();

        if ($labels->isNotEmpty()) {
            $rule->labels()->sync($labels->pluck('id')->all());
            $rule->touch();
        }

        return $this->json(['automation_rule' => $this->presentAutomationRule($rule)]);
    }
}
