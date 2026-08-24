<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\AutomationRule;
use App\Models\Label;

/**
 * The automation-rule shape every rule tool returns, so list_automation_rules,
 * create_automation_rule and update_automation_rule all hand the agent the same
 * row. Note that amounts inside `rules_json` are in MAJOR units, unlike every
 * other amount the MCP exposes.
 */
trait PresentsAutomationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function presentAutomationRule(AutomationRule $rule): array
    {
        $rule->loadMissing('labels:id,name');

        return [
            'id' => $rule->id,
            'title' => $rule->title,
            'priority' => $rule->priority,
            'rules_json' => $rule->rules_json,
            'action_category_id' => $rule->action_category_id,
            'action_note' => $rule->action_note,
            'origin' => $rule->origin->value,
            'labels' => $rule->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
        ];
    }
}
