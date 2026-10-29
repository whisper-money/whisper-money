<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * The surface a personal access token is issued for. Each surface owns an
 * ability namespace (`api:*`, `mcp:*`) and a plaintext prefix, so a token of
 * one surface is never accepted by the other and is recognisable at a glance.
 */
enum AccessTokenType: string
{
    case Api = 'api';
    case Mcp = 'mcp';

    /**
     * Prefix for tokens whose abilities belong to no surface (e.g. `['*']`).
     */
    public const string GenericPrefix = 'wm_';

    /**
     * The OAuth scope that opens the same surface to a connected app.
     */
    public function oauthScope(): OAuthScope
    {
        return match ($this) {
            self::Api => OAuthScope::Api,
            self::Mcp => OAuthScope::Mcp,
        };
    }

    public function prefix(): string
    {
        return self::GenericPrefix.$this->value.'_';
    }

    /**
     * Resolve the surface from a token's abilities: `api:*` abilities make an
     * API token, `mcp:*` abilities an MCP token, anything else none (null).
     *
     * @param  array<int, string>  $abilities
     *
     * @throws InvalidArgumentException when the abilities span both surfaces,
     *                                  which would let one token cross the line.
     */
    public static function fromAbilities(array $abilities): ?self
    {
        $types = array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->matches($abilities),
        ));

        if (count($types) > 1) {
            throw new InvalidArgumentException('A token cannot carry both API and MCP abilities.');
        }

        return $types[0] ?? null;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    public function matches(array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, $this->value.':')) {
                return true;
            }
        }

        return false;
    }
}
