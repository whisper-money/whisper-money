<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AutomationRuleLabel extends Pivot
{
    use HasUuids;

    protected $table = 'automation_rule_labels';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Attaching or detaching a label is an edit of the rule, so every path that
     * syncs them — the settings form, the MCP tools, savings goals — moves the
     * rule's `updated_at`, which is what tells the client its list is stale.
     *
     * @var list<string>
     */
    protected $touches = ['automationRule'];

    /** @return BelongsTo<AutomationRule, $this> */
    public function automationRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class);
    }
}
