<?php

namespace App\Models\Concerns;

use App\Enums\AccessTokenType;
use DateTimeInterface;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Sanctum tokens issued as `<prefix><40 random chars><crc32b checksum>`,
 * without Sanctum's `id|` part (and in place of `sanctum.token_prefix`): `wm_api_…` for `api:*` abilities, `wm_mcp_…` for `mcp:*`
 * abilities and plain `wm_…` for anything else (e.g. `['*']`). Sanctum's
 * `PersonalAccessToken::findToken()` resolves a token without `|` by hashing
 * the whole string, and legacy `id|…` tokens keep authenticating unchanged.
 */
trait HasPrefixedApiTokens
{
    use HasApiTokens;

    /**
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $entropy = Str::random(40);
        $plainTextToken = (AccessTokenType::fromAbilities($abilities)?->prefix() ?? AccessTokenType::GenericPrefix)
            .$entropy.hash('crc32b', $entropy);

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $plainTextToken);
    }
}
