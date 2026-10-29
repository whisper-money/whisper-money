<?php

namespace App\Mcp\Tools;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleApplier;
use App\Services\AutomationRules\RuleApplicationStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Apply an automation rule to transactions that already exist — rules otherwise only run on new ones. Previews the matches by default; pass dry_run false to actually apply them.')]
class ApplyAutomationRule extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_rule_id' => $schema->string()->description('Id of the automation rule to apply. Call list_automation_rules to see valid ids.')->required(),
            'dry_run' => $schema->boolean()->description('Default true: report how many transactions the rule matches, with a sample of them, and change nothing. Pass false to actually apply the rule\'s category, labels and note.'),
            'only_uncategorized' => $schema->boolean()->description('Default true: skip transactions that already have a category (or, for a label-only rule, already carry every label it would add). Passing false makes the rule overwrite categories the user or another rule already set, so preview that combination before applying it.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $rule = $this->ruleInSpace($request, $space);

        $dryRun = ! $request->has('dry_run') || $request->boolean('dry_run');
        $onlyUncategorized = ! $request->has('only_uncategorized') || $request->boolean('only_uncategorized');

        $application = app(AutomationRuleApplier::class)->applyInSpace($rule, $space, $dryRun, $onlyUncategorized);
        $total = $application->total;

        return $this->json(match ($application->status) {
            RuleApplicationStatus::Preview => [
                'dry_run' => true,
                'automation_rule_id' => $application->automationRuleId,
                'total_matches' => $total,
                'sample' => $application->sample
                    ->map(fn (Transaction $transaction): array => $this->presentTransaction($transaction))
                    ->all(),
                'next_step' => $total === 0
                    ? 'Nothing matched, so there is nothing to apply. Widen the rule with update_automation_rule, or set only_uncategorized false to include transactions that already have a category.'
                    : "Nothing was changed. Call apply_automation_rule again with dry_run false to apply the rule to all {$total} matching transactions.",
            ],
            RuleApplicationStatus::Queued => [
                'status' => 'queued',
                'automation_rule_id' => $application->automationRuleId,
                'total' => $total,
                'message' => 'Too many transactions to apply inline, so this finishes in the background over the next few minutes. There is no progress tool: call apply_automation_rule with dry_run true again to see what the rule still matches.',
            ],
            RuleApplicationStatus::Done => [
                'status' => 'done',
                'automation_rule_id' => $application->automationRuleId,
                'total' => $total,
                // `applied` counts the transactions the rule ran over, `updated` the
                // ones it actually changed — a row that already carried the rule's
                // category and labels is applied but not updated.
                'applied' => $application->applied,
                'updated' => $application->updated,
            ],
        });
    }
}
