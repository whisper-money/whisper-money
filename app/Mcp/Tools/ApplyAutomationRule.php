<?php

namespace App\Mcp\Tools;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleApplier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Apply an automation rule to transactions that already exist — rules otherwise only run on new ones. Previews the matches by default; pass dry_run false to actually apply them.')]
class ApplyAutomationRule extends WriteTool
{
    /**
     * How many matching transactions a preview lists. The full count is reported
     * separately: the sample is only there to let the agent sanity check what
     * the rule caught before committing to it.
     */
    private const SAMPLE_SIZE = 20;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_rule_id' => $schema->string()->description('Id of the automation rule to apply. Call list_automation_rules to see valid ids.')->required(),
            'dry_run' => $schema->boolean()->description('Default true: report how many transactions the rule matches, with a sample of them, and change nothing. Pass false to actually apply the rule\'s category, labels and note.'),
            'only_uncategorized' => $schema->boolean()->description('Default true: skip transactions that already have a category (or, for a label-only rule, already carry every label it would add), so an existing categorization is never overwritten.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $rule = $this->ruleInSpace($request, $space);

        $dryRun = ! $request->has('dry_run') || $request->boolean('dry_run');
        $onlyUncategorized = ! $request->has('only_uncategorized') || $request->boolean('only_uncategorized');

        $applier = app(AutomationRuleApplier::class);
        $matchingIds = $applier->matchingIds($rule, $onlyUncategorized, $space);
        $total = count($matchingIds);

        if ($dryRun) {
            return $this->json([
                'dry_run' => true,
                'automation_rule_id' => $rule->id,
                'total_matches' => $total,
                'sample' => $this->sample($matchingIds),
                'next_step' => "Nothing was changed. Call apply_automation_rule again with dry_run false to apply the rule to all {$total} matching transactions.",
            ]);
        }

        if ($total > AutomationRuleApplier::SYNC_THRESHOLD) {
            $applier->queue($rule, $matchingIds, $onlyUncategorized, $space);

            return $this->json([
                'status' => 'queued',
                'automation_rule_id' => $rule->id,
                'total' => $total,
                'message' => 'Too many transactions to apply inline, so this finishes in the background over the next few minutes. Call apply_automation_rule with dry_run true again to see what is still unmatched.',
            ]);
        }

        $result = $applier->applyNow($rule, $matchingIds, $onlyUncategorized, $space);

        return $this->json([
            'status' => 'done',
            'automation_rule_id' => $rule->id,
            'total' => $total,
            // `applied` counts the transactions the rule ran over, `updated` the
            // ones it actually changed — a row that already carried the rule's
            // category and labels is applied but not updated.
            'applied' => $result['applied'],
            'updated' => $result['updated'],
        ]);
    }

    /**
     * @param  array<int, string>  $matchingIds
     * @return array<int, array<string, mixed>>
     */
    private function sample(array $matchingIds): array
    {
        $ids = array_slice($matchingIds, 0, self::SAMPLE_SIZE);

        if ($ids === []) {
            return [];
        }

        return Transaction::query()
            ->whereIn('id', $ids)
            ->with(['account:id,name', 'category:id,name', 'labels:id,name'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Transaction $transaction): array => $this->presentTransaction($transaction))
            ->all();
    }
}
