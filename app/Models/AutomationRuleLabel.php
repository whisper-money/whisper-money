<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AutomationRuleLabel extends Pivot
{
    use HasUuids;

    protected $table = 'automation_rule_labels';

    public $incrementing = false;

    protected $keyType = 'string';
}
