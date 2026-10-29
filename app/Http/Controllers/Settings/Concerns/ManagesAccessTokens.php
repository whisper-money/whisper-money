<?php

namespace App\Http\Controllers\Settings\Concerns;

use App\Enums\AccessTokenType;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared by the settings screens that manage one surface's personal access
 * tokens (MCP, API). Each screen lists, rotates and revokes only the tokens
 * that belong to it, so a token of one surface is never touched from the other.
 */
trait ManagesAccessTokens
{
    /**
     * The surface whose abilities this screen hands out.
     */
    abstract protected function tokenType(): AccessTokenType;

    /**
     * Whether the token is listed and manageable on this screen.
     */
    abstract protected function managesToken(PersonalAccessToken $token): bool;

    /**
     * The abilities for the scope picked in the form: `read` grants
     * `<surface>:read`, `read_write` adds `<surface>:write`.
     *
     * @return list<string>
     */
    protected function abilitiesFor(string $scope): array
    {
        $type = $this->tokenType()->value;

        return $scope === 'read_write'
            ? ["{$type}:read", "{$type}:write"]
            : ["{$type}:read"];
    }

    /**
     * Ensure the token belongs to the requesting user before mutating it, and
     * that it is managed from this screen (404 otherwise).
     */
    protected function authorizeOwnership(Request $request, PersonalAccessToken $token): void
    {
        abort_unless(
            $token->tokenable_id === $request->user()->getKey()
                && $token->tokenable_type === $request->user()->getMorphClass(),
            403
        );

        abort_unless($this->managesToken($token), 404);
    }

    /**
     * Revoke the token and issue a fresh secret with the same name and
     * abilities (and so the same prefix). Returns the new plaintext secret.
     * Both happen in one transaction, so a failure never leaves the old and
     * the new token alive side by side. An expired token is refused: rotating
     * it would hand out a working secret for access that has already ended.
     *
     * @throws ValidationException
     */
    protected function reissue(Request $request, PersonalAccessToken $token, ?DateTimeInterface $expiresAt = null): string
    {
        if ($token->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'token' => __('This token has expired and cannot be rotated. Create a new one instead.'),
            ]);
        }

        return DB::transaction(function () use ($request, $token, $expiresAt): string {
            $fresh = $request->user()->createToken($token->name, $token->abilities, $expiresAt);
            $token->delete();

            return $fresh->plainTextToken;
        });
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    protected function managedTokens(Request $request): Collection
    {
        return $request->user()->tokens()
            ->latest()
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => $this->managesToken($token))
            ->values();
    }

    /**
     * @return array{id: int|string, name: string, scope: string, created_at: ?string, last_used_at: ?string}
     */
    protected function tokenRow(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'scope' => $token->can($this->tokenType()->value.':write') ? 'read_write' : 'read',
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
        ];
    }
}
