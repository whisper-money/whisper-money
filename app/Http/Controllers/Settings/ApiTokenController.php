<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccessTokenType;
use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\ManagesAccessTokens;
use App\Http\Controllers\Settings\Concerns\ManagesConnectedApps;
use App\Http\Requests\Settings\StoreApiTokenRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Settings → API: the user's personal API tokens (`api:*` abilities). MCP
 * tokens are managed from the AI Connector screen and never show up here.
 */
class ApiTokenController extends Controller
{
    use ManagesAccessTokens, ManagesConnectedApps;

    /**
     * Show the API page: existing tokens, the connected OAuth apps, the base URL and the one-time
     * plaintext secret when a token was just created or rotated. Hidden from
     * the shared demo and press accounts, which the API refuses anyway.
     */
    public function index(Request $request): Response
    {
        abort_if($request->user()->isRestrictedSharedAccount(), 404);

        return Inertia::render('settings/api', [
            'connectedApps' => $this->connectedApps($request),
            'tokens' => $this->managedTokens($request)
                ->map(fn (PersonalAccessToken $token): array => [
                    ...$this->tokenRow($token),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'expired' => $token->expires_at?->isPast() ?? false,
                ])
                ->all(),
            'baseUrl' => url('/api/v1'),
            'docsUrl' => route('documentation.show', ['slug' => 'api']),
            'subscribeUrl' => route('subscribe'),
            'newToken' => $request->session()->get('api_token'),
        ]);
    }

    /**
     * Create a new API token. The plaintext secret is flashed once; only its
     * hash is stored, so it can never be shown again. A "read" token carries
     * `api:read`; a "read_write" token adds `api:write`.
     */
    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $token = $request->user()->createToken(
            $request->validated('name'),
            $this->abilitiesFor($request->validated('scope')),
            $request->expiry()->expiresAt(),
        );

        return to_route('api-tokens.index')->with('api_token', $token->plainTextToken);
    }

    /**
     * Revoke (delete) an API token the user owns. Allowed on any plan, so a
     * lapsed subscription never stands in the way of cutting off access.
     */
    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $this->authorizeOwnership($request, $token);

        $token->delete();

        return to_route('api-tokens.index');
    }

    /**
     * Rotate an API token: revoke it and issue a fresh secret keeping the same
     * name and abilities. The expiry keeps the same lifetime the token was
     * created with, counted from now (a 90-day token rotated today expires in
     * 90 days), and a token that never expired still never does. Like
     * creating one, it needs a Pro plan; revoking does not.
     */
    public function rotate(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $this->authorizeOwnership($request, $token);

        abort_unless($request->user()->canUseFeature(PlanFeature::ApiAccess), 403);

        return to_route('api-tokens.index')->with('api_token', $this->reissue($request, $token, $this->renewedExpiry($token)));
    }

    /**
     * The expiry of a rotated token: the lifetime it was created with,
     * counted from now, or none if it never expired.
     */
    private function renewedExpiry(PersonalAccessToken $token): ?CarbonImmutable
    {
        if ($token->expires_at === null) {
            return null;
        }

        return CarbonImmutable::now()->addSeconds((int) $token->created_at->diffInSeconds($token->expires_at));
    }

    protected function tokenType(): AccessTokenType
    {
        return AccessTokenType::Api;
    }

    /**
     * Only `api:*` tokens: the API refuses wildcard and MCP tokens, so they
     * are not managed from here.
     */
    protected function managesToken(PersonalAccessToken $token): bool
    {
        return AccessTokenType::Api->matches($token->abilities ?? []);
    }
}
