<?php

namespace App\Mcp\Tools\Concerns;

use App\Features\CustomDashboards;
use App\Mcp\Tools\McpTool;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Pennant\Feature;

/**
 * Custom dashboards and widgets are behind the CustomDashboards flag, the
 * only gate on this instance (there are no plans). A user without it never
 * sees these tools or the widget preview: they are left out of `tools/list`
 * and `resources/list`, and a call by name answers "not found". The refusal
 * stays as a second check, before the MCP's own gate
 * ({@see McpTool::refusal()}), so the agent gets a message it can pass on.
 * The shared demo account is refused by McpTool itself.
 */
trait GuardsCustomDashboards
{
    /**
     * @api Called by Laravel MCP (Primitive::eligibleForRegistration()) on
     *      every list and call, never by application code.
     */
    public function shouldRegister(Request $request): bool
    {
        return self::customDashboardsEnabledFor($request->user());
    }

    protected function refusal(User $user): ?Response
    {
        if (! self::customDashboardsEnabledFor($user)) {
            return Response::error('Custom dashboards and widgets are not enabled for this account.');
        }

        return null;
    }

    private static function customDashboardsEnabledFor(mixed $user): bool
    {
        return $user instanceof User && Feature::for($user)->active(CustomDashboards::class);
    }
}
