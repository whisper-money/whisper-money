<?php

namespace App\Services\AutomationRules;

/**
 * How applying a rule over existing transactions ended (see RuleApplication).
 */
enum RuleApplicationStatus: string
{
    case Preview = 'preview';
    case Done = 'done';
    case Queued = 'queued';
}
