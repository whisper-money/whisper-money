<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The space an MCP tool or `/api/v1` request operates on: the optional
 * `space` argument (validated against the spaces the user can access) or the
 * user's personal space.
 *
 * Permissions mirror the web app, which only ever shows a user their own
 * rows: membership of a shared space (`accessibleSpaces`) picks the space, and
 * every row read, written or referenced inside it must also be the caller's
 * (`user_id`). Look rows up through ownedInSpace() or ownedForApiRequest(),
 * never with a bare `forSpace()`.
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

    /**
     * The space a `/api/v1` request operates on, for its controllers and form
     * requests alike.
     */
    public function forApiRequest(Request $request): Space
    {
        /** @var User $user */
        $user = $request->user();

        return $this->resolve($user, $request->string('space')->toString(), 'List the valid ids with GET /api/v1/spaces.');
    }

    /**
     * Narrows $query to the caller's own rows in $space. Another member's row
     * is left out like one that does not exist, so a `findOrFail()` on it is
     * a 404.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function ownedInSpace(Builder $query, User $user, Space $space): Builder
    {
        return $query
            ->where($query->qualifyColumn('space_id'), $space->id)
            ->where($query->qualifyColumn('user_id'), $user->id);
    }

    /**
     * Narrows $query to the caller's own rows in the space a `/api/v1`
     * request operates on, the same way as ownedInSpace().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function ownedForApiRequest(Request $request, Builder $query): Builder
    {
        /** @var User $user */
        $user = $request->user();

        return $this->ownedInSpace($query, $user, $this->forApiRequest($request));
    }

    /**
     * One of the caller's own categories, labels, automation rules or savings
     * goals in $space, the same way as
     * ownedInSpace(): another member's is a ModelNotFoundException like one
     * that does not exist.
     *
     * @template TModel of AutomationRule|Category|Label|SavingsGoal
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public function ownedInSpaceOrFail(string $model, User $user, Space $space, string $id): AutomationRule|Category|Label|SavingsGoal
    {
        return $model::query()->forSpace($space)->where('user_id', $user->id)->findOrFail($id);
    }
}
