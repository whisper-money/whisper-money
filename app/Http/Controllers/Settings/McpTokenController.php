<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreMcpTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenController extends Controller
{
    /**
     * Show the MCP access page: existing tokens, connection details and the
     * one-time plaintext secret when a token was just created.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('settings/mcp', [
            'tokens' => $this->tokensFor($request),
            'serverUrl' => url('/mcp'),
            'subscribeUrl' => route('subscribe'),
            'newToken' => $request->session()->get('mcp_token'),
        ]);
    }

    /**
     * Create a new MCP token. The plaintext secret is flashed once; only its
     * hash is stored, so it can never be shown again.
     */
    public function store(StoreMcpTokenRequest $request): RedirectResponse
    {
        $abilities = $request->validated('scope') === 'read_write'
            ? ['mcp:read', 'mcp:write']
            : ['mcp:read'];

        $token = $request->user()->createToken($request->validated('name'), $abilities);

        return to_route('mcp.index')->with('mcp_token', $token->plainTextToken);
    }

    /**
     * Revoke (delete) a token the user owns.
     */
    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless(
            $token->tokenable_id === $request->user()->getKey()
                && $token->tokenable_type === $request->user()->getMorphClass(),
            403
        );

        $token->delete();

        return to_route('mcp.index');
    }

    /**
     * Rotate a token: revoke it and issue a fresh secret keeping the same name
     * and scope, so a leaked token can be replaced without reconfiguring intent.
     */
    public function rotate(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        abort_unless(
            $token->tokenable_id === $request->user()->getKey()
                && $token->tokenable_type === $request->user()->getMorphClass(),
            403
        );

        $fresh = $request->user()->createToken($token->name, $token->abilities);
        $token->delete();

        return to_route('mcp.index')->with('mcp_token', $fresh->plainTextToken);
    }

    /**
     * @return list<array{id: int|string, name: string, scope: string, created_at: ?string, last_used_at: ?string}>
     */
    private function tokensFor(Request $request): array
    {
        return $request->user()->tokens()
            ->latest()
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'scope' => in_array('mcp:write', $token->abilities ?? [], true) ? 'read_write' : 'read',
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ])
            ->all();
    }
}
