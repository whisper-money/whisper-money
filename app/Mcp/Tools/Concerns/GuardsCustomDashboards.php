<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Tools\McpTool;
use App\Models\Dashboard;
use App\Models\User;
use Laravel\Mcp\Response;

/**
 * Custom dashboards and widgets are part of the paid Standard plan and never
 * open to the shared demo and press accounts, whose data everyone holding the
 * credentials sees. Checked before the MCP's own gate ({@see McpTool::refusal()}),
 * so the agent gets a message about dashboards it can pass on to the user.
 * Not behind the CustomDashboards flag: that one only hides the web UI.
 */
trait GuardsCustomDashboards
{
    protected function refusal(User $user): ?Response
    {
        if ($user->isRestrictedSharedAccount()) {
            return Response::error('Custom dashboards and widgets are not available on the shared demo and press accounts.');
        }

        if (! $user->can('create', Dashboard::class)) {
            return Response::error(
                'Custom dashboards and widgets are part of the paid Standard plan, and this user is not on it. Tell the user they can upgrade at '.route('subscribe').' and ask again afterwards.'
            );
        }

        return null;
    }
}
