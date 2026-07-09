<?php

namespace App\Http\Controllers;

use App\Actions\CreateDefaultCategories;
use App\Features\Spaces;
use App\Http\Requests\StoreSpaceRequest;
use App\Http\Requests\UpdateSpaceRequest;
use App\Models\Space;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class SpaceController extends Controller
{
    /**
     * The spaces management settings page. The space list is shared globally
     * (see HandleInertiaRequests); here we add the members and pending
     * invitations of the spaces the current user owns, plus the seat usage.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $managed = $user->ownedSpaces()
            ->where('personal', false)
            ->with(['members:id,name,email', 'invitations' => fn ($query) => $query->whereNull('accepted_at')])
            ->get()
            ->map(fn (Space $space): array => [
                'id' => $space->id,
                'name' => $space->name,
                'members' => $space->members->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                ])->all(),
                'invitations' => $space->invitations
                    ->reject(fn ($invitation): bool => $invitation->isExpired())
                    ->map(fn ($invitation): array => [
                        'id' => $invitation->id,
                        'email' => $invitation->email,
                    ])->values()->all(),
            ]);

        return Inertia::render('settings/spaces', [
            'managedSpaces' => $managed,
            'seatsInUse' => $user->seatsInUse(),
            'maxSeats' => (int) config('spaces.max_seats'),
        ]);
    }

    /**
     * Create a new space, seed it with the default categories and switch to it.
     */
    public function store(StoreSpaceRequest $request): RedirectResponse
    {
        $user = $request->user();

        $space = DB::transaction(function () use ($user, $request): Space {
            $space = $user->ownedSpaces()->create([
                'name' => $request->validated('name'),
                'personal' => false,
            ]);

            app(CreateDefaultCategories::class)->handle($user, $space);

            return $space;
        });

        $user->forceFill(['current_space_id' => $space->id])->save();

        return back();
    }

    /**
     * Rename a space (owner only; the personal space name is fixed).
     */
    public function update(UpdateSpaceRequest $request, Space $space): RedirectResponse
    {
        $space->update(['name' => $request->validated('name')]);

        return back();
    }

    /**
     * Delete a space the user owns. The personal space is permanent, and a space
     * that still holds accounts must be emptied first — financial data is never
     * deleted implicitly.
     */
    public function destroy(Request $request, Space $space): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            ! $space->personal
                && $space->owner_id === $user->id
                && Feature::for($user)->active(Spaces::class),
            403,
        );

        if ($space->accounts()->exists()) {
            return back()->with('error', __('Remove or move this space\'s accounts before deleting it.'));
        }

        if ($user->current_space_id === $space->id) {
            $user->forceFill(['current_space_id' => $user->personalSpace->id])->save();
        }

        $space->delete();

        return back();
    }

    /**
     * Switch the active space. Any space the user owns or belongs to is allowed;
     * the personal space is always reachable so this never requires the flag.
     */
    public function select(Request $request, Space $space): RedirectResponse
    {
        $user = $request->user();

        abort_unless($space->hasMember($user), 403);

        $user->forceFill(['current_space_id' => $space->id])->save();

        return back();
    }

    /**
     * Remove a member from a space (owner only), sending them back to their
     * personal space so they never sit on a pointer to a space they've lost.
     */
    public function removeMember(Request $request, Space $space, User $member): RedirectResponse
    {
        abort_unless($space->owner_id === $request->user()->id, 403);

        $space->members()->detach($member->id);

        if ($member->current_space_id === $space->id) {
            $member->forceFill(['current_space_id' => $member->personalSpace->id])->save();
        }

        return back()->with('success', __('Member removed.'));
    }

    /**
     * Leave a space you were invited to (members only; an owner cannot leave
     * their own space).
     */
    public function leave(Request $request, Space $space): RedirectResponse
    {
        $user = $request->user();

        abort_if($space->owner_id === $user->id, 403);
        abort_unless($space->members()->whereKey($user->id)->exists(), 403);

        $space->members()->detach($user->id);

        if ($user->current_space_id === $space->id) {
            $user->forceFill(['current_space_id' => $user->personalSpace->id])->save();
        }

        return back()->with('success', __('You\'ve left :space.', ['space' => $space->name]));
    }
}
