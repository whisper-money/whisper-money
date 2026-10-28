<?php

namespace App\Services\AutomationRules;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * What applying a rule over existing transactions did: a preview that changed
 * nothing, a run done inline, or one handed to the queue because it matched
 * too many transactions (see AutomationRuleApplier::applyInSpace()).
 */
final readonly class RuleApplication
{
    /**
     * @param  string  $automationRuleId  the rule that was applied
     * @param  int  $total  how many transactions the rule matched
     * @param  Collection<int, Transaction>  $sample  a preview's first matches, newest first
     * @param  int|null  $applied  the transactions an inline run went over, null unless it is done
     * @param  int|null  $updated  the ones it changed, null unless it is done: a row that already carried the rule's category and labels is applied but not updated
     */
    public function __construct(
        public RuleApplicationStatus $status,
        public string $automationRuleId,
        public int $total,
        public Collection $sample = new Collection,
        public ?int $applied = null,
        public ?int $updated = null,
    ) {}
}
