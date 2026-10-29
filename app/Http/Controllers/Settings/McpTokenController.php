<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccessTokenType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\ManagesAccessTokens;
use App\Http\Controllers\Settings\Concerns\ManagesConnectedApps;
use App\Http\Requests\Settings\StoreMcpTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenController extends Controller
{
    use ManagesAccessTokens, ManagesConnectedApps;

    /**
     * Show the MCP access page: existing tokens, the connected OAuth apps,
     * connection details and the
     * one-time plaintext secret when a token was just created.
     */
    public function index(Request $request): Response
    {
        abort_if($request->user()->isRestrictedDemoAccount(), 404);

        return Inertia::render('settings/mcp', [
            'connectedApps' => $this->connectedApps($request),
            'tokens' => $this->managedTokens($request)
                ->map(fn (PersonalAccessToken $token): array => $this->tokenRow($token))
                ->all(),
            'serverUrl' => url('/mcp'),
            'oauthUrl' => url('/mcp/oauth'),
            'subscribeUrl' => route('subscribe'),
            'newToken' => $request->session()->get('mcp_token'),
        ]);
    }

    /**
     * Create a new MCP token. The plaintext secret is flashed once; only its
     * hash is stored, so it can never be shown again. A "read" token can only
     * analyse data; a "read_write" token additionally carries `mcp:write`,
     * unlocking the write tools.
     */
    public function store(StoreMcpTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken(
            $request->validated('name'),
            $this->abilitiesFor($request->validated('scope')),
        );

        return to_route('mcp.index')->with('mcp_token', $token->plainTextToken);
    }

    /**
     * Revoke (delete) an MCP token the user owns.
     */
    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $this->authorizeOwnership($request, $token);

        $token->delete();

        return to_route('mcp.index');
    }

    /**
     * Rotate an MCP token: revoke it and issue a fresh secret keeping the same
     * name and abilities (and so the `wm_mcp_` prefix), so a leaked token can be
     * replaced without reconfiguring intent.
     */
    public function rotate(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $this->authorizeOwnership($request, $token);

        return to_route('mcp.index')->with('mcp_token', $this->reissue($request, $token));
    }

    protected function tokenType(): AccessTokenType
    {
        return AccessTokenType::Mcp;
    }

    /**
     * Whether the token belongs on this screen: it carries `mcp:*` abilities,
     * or the `*` wildcard, which also authenticates at `/mcp`. API tokens are
     * not managed from here.
     */
    protected function managesToken(PersonalAccessToken $token): bool
    {
        $abilities = $token->abilities ?? [];

        return AccessTokenType::Mcp->matches($abilities) || in_array('*', $abilities, true);
    }
}
