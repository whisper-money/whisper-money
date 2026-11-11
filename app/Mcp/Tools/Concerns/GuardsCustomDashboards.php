<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Dashboard;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Custom dashboards and widgets are part of the paid Standard plan and never
 * open to the shared demo and press accounts, whose data everyone holding the
 * credentials sees. Checked before the MCP's own gate, so the agent gets a
 * message about dashboards it can pass on to the user. Not behind the
 * CustomDashboards flag: that one only hides the web UI.
 */
trait GuardsCustomDashboards
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if ($user instanceof User) {
            if ($user->isRestrictedSharedAccount()) {
                return Response::error('Custom dashboards and widgets are not available on the shared demo and press accounts.');
            }

            if (! $user->can('create', Dashboard::class)) {
                return Response::error(
                    'Custom dashboards and widgets are part of the paid Standard plan, and this user is not on it. Tell the user they can upgrade at '.route('subscribe').' and ask again afterwards.'
                );
            }
        }

        return parent::handle($request);
    }
}
