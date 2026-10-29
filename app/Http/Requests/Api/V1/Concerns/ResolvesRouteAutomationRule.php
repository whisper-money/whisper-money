<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\AutomationRule;

/**
 * For the `/api/v1/automation-rules/{automation_rule}` write requests: the
 * rule is looked up in the request's space, and only the member who created
 * it may change, apply or delete it (see ResolvesOwnedRouteModel).
 */
trait ResolvesRouteAutomationRule
{
    use ResolvesOwnedRouteModel;

    public function automationRule(): AutomationRule
    {
        /** @var AutomationRule */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [AutomationRule::class, 'automation_rule'];
    }
}
