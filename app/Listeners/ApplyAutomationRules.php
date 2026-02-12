<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Services\AutomationRuleService;

class ApplyAutomationRules
{
    public function __construct(protected AutomationRuleService $automationRuleService) {}

    public function handle(TransactionCreated $event): void
    {
        $this->automationRuleService->applyRules($event->transaction);
    }
}
