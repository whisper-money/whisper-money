<?php

namespace App\Http\Controllers;

use App\Actions\CreateDefaultCategories;
use App\Features\Spaces;
use App\Http\Requests\StoreSpaceRequest;
use App\Http\Requests\UpdateSpaceRequest;
use App\Models\Space;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class SpaceController extends Controller
{
    /**
     * The spaces management settings page. The list itself is shared globally
     * (see HandleInertiaRequests), so this only needs to render the page.
     */
    public function index(): Response
    {
        return Inertia::render('settings/spaces');
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
}
