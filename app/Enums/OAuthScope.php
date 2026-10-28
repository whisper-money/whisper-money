<?php

namespace App\Enums;

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * The OAuth scopes Passport hands out, one per surface. A token carries
 * exactly one of them: `mcp:use` opens `/mcp/oauth`, `api:use` opens
 * `/api/v1`, and an authorization request asking for both is refused, so an
 * app connected to one surface never reaches the other.
 */
enum OAuthScope: string
{
    case Mcp = 'mcp:use';
    case Api = 'api:use';

    public function description(): string
    {
        return match ($this) {
            self::Mcp => 'Use MCP server',
            self::Api => 'Use the Whisper Money API',
        };
    }

    /**
     * Merge every scope into Passport's registry, keeping whatever is there
     * already. `Mcp::oauthRoutes()` and the MCP service provider merge
     * `mcp:use` in the same way, so the order they all run in does not matter.
     *
     * @return array<string, string>
     */
    public static function register(): array
    {
        $scopes = Passport::$scopes;

        foreach (self::cases() as $scope) {
            $scopes[$scope->value] ??= $scope->description();
        }

        Passport::tokensCan($scopes);

        return $scopes;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scope): string => $scope->value, self::cases());
    }

    /**
     * Whether the requested scope ids span more than one surface.
     *
     * @param  array<int, string>  $scopeIds
     */
    public static function spansSurfaces(array $scopeIds): bool
    {
        return count(array_intersect(self::values(), $scopeIds)) > 1;
    }

    /**
     * The surface an authorization request is for. No scope means MCP, the
     * default scope the Claude and ChatGPT connectors have always received.
     *
     * @param  array<int, string>  $scopeIds
     */
    public static function fromRequested(array $scopeIds): self
    {
        return in_array(self::Api->value, $scopeIds, true) ? self::Api : self::Mcp;
    }

    /**
     * Why the user may not approve a request for this scope, or null when
     * they may. Matches the `/api/v1` gate (EnsureApiAccess): the shared demo
     * and press accounts never can, and anyone else needs the API plan
     * feature. MCP connections are not gated here; each tool checks the plan.
     */
    public function approvalRefusalFor(User $user): ?OAuthApprovalRefusal
    {
        if ($this !== self::Api) {
            return null;
        }

        if ($user->isRestrictedSharedAccount()) {
            return OAuthApprovalRefusal::SharedAccount;
        }

        return $user->canUseFeature(PlanFeature::ApiAccess) ? null : OAuthApprovalRefusal::PlanRequired;
    }

    /**
     * The scope's name as the settings screens show it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mcp => __('AI Connector'),
            self::Api => __('API'),
        };
    }
}
