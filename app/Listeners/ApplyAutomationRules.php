<?php

namespace App\Listeners;

use App\Enums\CategorySource;
use App\Events\TransactionCreated;
use App\Services\AutomationRuleService;

class ApplyAutomationRules
{
    public function __construct(protected AutomationRuleService $automationRuleService) {}

    /**
     * Apply the user's rules to a transaction that was just created — unless a
     * human already categorized it on the way in.
     *
     * A brand-new row carrying `manual` was categorized on purpose seconds ago:
     * the split dialog, a hand-made transaction, the MCP tools. Letting a rule
     * fire here overwrote that choice (and force-synced the rule's labels onto
     * it). Re-evaluation is a different path — there the user asked for the
     * rules to run — and is deliberately left alone.
     *
     * A row a full import writes is left to the import's own budget pass: the
     * chunk is reassigned in one batch, without budget emails, once the rules
     * have filed every row in it. Reassigning it here as well would queue a
     * job per row that emails about limits crossed long ago. One part of a
     * split is left to the split's own budget pass, which runs once the split
     * has committed and sends no budget email: a split is not new spending.
     */
    public function handle(TransactionCreated $event): void
    {
        if ($event->transaction->category_source === CategorySource::Manual) {
            return;
        }

        $this->automationRuleService->applyRules(
            $event->transaction,
            reassignBudgets: $event->transaction->import_id === null && ! $event->transaction->isSplitPart(),
        );
    }
}
