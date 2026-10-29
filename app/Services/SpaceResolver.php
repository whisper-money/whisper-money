<?php

namespace App\Services;

use App\Models\Space;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The space an MCP tool or `/api/v1` request operates on: the optional
 * `space` argument (validated against the spaces the user can access) or the
 * user's personal space.
 *
 * Scoping is by `space_id` only, gated by membership (`accessibleSpaces`): a
 * space is a shared tenant, so a member is meant to see every row in it. The
 * security boundary is the membership check here, not a per-row `user_id`
 * filter.
 */
class SpaceResolver
{
    /**
     * @param  string  $hint  appended to the failure message, telling the caller where the valid ids are listed
     */
    public function resolve(User $user, string $spaceId, string $hint): Space
    {
        if ($spaceId === '') {
            return $user->personalSpace ?? $user->activeSpace();
        }

        $space = $user->accessibleSpaces()->firstWhere('id', $spaceId);

        if ($space === null) {
            throw ValidationException::withMessages([
                'space' => "You do not have access to a space with id {$spaceId}. {$hint}",
            ]);
        }

        return $space;
    }
}
