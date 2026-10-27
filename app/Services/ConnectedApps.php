<?php

namespace App\Services;

use App\Enums\OAuthScope;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Passport\AuthCode;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * The OAuth apps a user has connected to one surface (MCP or API): every
 * client holding a live access token with that surface's scope. Settings →
 * AI Connector lists `mcp:use` apps and Settings → API lists `api:use` ones,
 * so revoking an app from one screen never disconnects it from the other.
 */
class ConnectedApps
{
    /**
     * One row per client. `last_used_at` is only known for the API, which
     * records the client of every request; MCP tool calls do not.
     *
     * @return list<array{id: string, name: string, scopes: list<string>, connected_at: ?string, last_used_at: ?string}>
     */
    public function for(User $user, OAuthScope $scope): array
    {
        $tokens = $this->liveTokens($user, $scope)->with('client')->oldest()->get();
        $lastUsed = $this->lastUsedAt($user, $scope, $tokens->pluck('client_id')->unique()->all());

        return $tokens
            ->groupBy('client_id')
            ->map(fn (Collection $clientTokens, string $clientId): array => [
                'id' => $clientId,
                'name' => $clientTokens->first()->client?->name ?? $clientId,
                'scopes' => $clientTokens->pluck('scopes')->flatten()->unique()->values()->all(),
                'connected_at' => $clientTokens->first()->created_at?->toIso8601String(),
                'last_used_at' => $lastUsed[$clientId] ?? null,
            ])
            ->sortByDesc(fn (array $app): string => $app['last_used_at'] ?? $app['connected_at'] ?? '')
            ->values()
            ->all();
    }

    /**
     * Disconnect a client: revoke every access token, refresh token and
     * pending authorization code it holds for this user under this scope.
     * Returns how many access tokens were revoked (0 when the client has no
     * connection to revoke).
     */
    public function revoke(User $user, OAuthScope $scope, string $clientId): int
    {
        $tokenIds = $this->held(Token::class, $user, $scope)->where('client_id', $clientId)->pluck('id');

        RefreshToken::query()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        $this->held(AuthCode::class, $user, $scope)->where('client_id', $clientId)->update(['revoked' => true]);

        return Token::query()->whereIn('id', $tokenIds)->update(['revoked' => true]);
    }

    /**
     * Access tokens that still work, or can still be refreshed into one.
     *
     * @return Builder<Token>
     */
    private function liveTokens(User $user, OAuthScope $scope): Builder
    {
        return $this->held(Token::class, $user, $scope)
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '>', now())
                ->orWhereHas('refreshToken', fn (Builder $refresh) => $refresh
                    ->where('revoked', false)
                    ->where('expires_at', '>', now())));
    }

    /**
     * The user's unrevoked tokens or codes of the given Passport model that
     * carry the scope.
     *
     * @template TModel of Token|AuthCode
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    private function held(string $model, User $user, OAuthScope $scope): Builder
    {
        return $model::query()
            ->where('user_id', $user->getKey())
            ->where('revoked', false)
            ->whereJsonContains('scopes', $scope->value);
    }

    /**
     * @param  list<string>  $clientIds
     * @return array<string, string>
     */
    private function lastUsedAt(User $user, OAuthScope $scope, array $clientIds): array
    {
        if ($scope !== OAuthScope::Api || $clientIds === []) {
            return [];
        }

        return ApiRequest::query()
            ->where('user_id', $user->getKey())
            ->whereIn('oauth_client_id', $clientIds)
            ->groupBy('oauth_client_id')
            ->selectRaw('oauth_client_id, max(created_at) as last_used_at')
            ->pluck('last_used_at', 'oauth_client_id')
            ->map(fn (string $at): string => Carbon::parse($at)->toIso8601String())
            ->all();
    }
}
