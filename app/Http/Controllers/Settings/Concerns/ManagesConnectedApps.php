<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Enums\AccessTokenType;
use App\Services\ConnectedApps;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Shared by the settings screens that list the OAuth apps connected to their
 * surface (MCP, API) and let the user disconnect one. Only the apps holding
 * this surface's scope are listed or revoked.
 */
trait ManagesConnectedApps
{
    /**
     * The surface whose connected apps this screen manages.
     */
    abstract protected function tokenType(): AccessTokenType;

    /**
     * @return list<array{id: string, name: string, scopes: list<string>, connected_at: ?string, last_used_at: ?string}>
     */
    protected function connectedApps(Request $request): array
    {
        return app(ConnectedApps::class)->for($request->user(), $this->tokenType()->oauthScope());
    }

    /**
     * Disconnect an app: revoke all its tokens (and refresh tokens) for this
     * user on this surface. Allowed on any plan, like revoking a token. A
     * client with nothing to revoke here is a 404.
     */
    public function destroyApp(Request $request, ConnectedApps $apps, string $client): RedirectResponse
    {
        abort_if($apps->revoke($request->user(), $this->tokenType()->oauthScope(), $client) === 0, 404);

        return back();
    }
}
