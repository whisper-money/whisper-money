<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSpaceInvitationRequest;
use App\Mail\SpaceInvitationMail;
use App\Models\Space;
use App\Models\SpaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SpaceInvitationController extends Controller
{
    /**
     * Invite someone to a space by email. Enforces the subscription seat cap and
     * refuses duplicates (already a member or already invited).
     */
    public function store(StoreSpaceInvitationRequest $request, Space $space): RedirectResponse
    {
        $user = $request->user();
        $email = strtolower($request->validated('email'));

        if ($space->members()->where('email', $email)->exists() || strcasecmp($email, $user->email) === 0) {
            return back()->with('error', __('That person is already a member of this space.'));
        }

        if ($user->seatsInUse() >= config('spaces.max_seats')) {
            return back()->with('error', __('You\'ve reached the :count-user limit for your plan.', [
                'count' => (int) config('spaces.max_seats'),
            ]));
        }

        $invitation = $space->invitations()->updateOrCreate(
            ['email' => $email, 'accepted_at' => null],
            [
                'invited_by_id' => $user->id,
                'role' => 'member',
                'token' => Str::random(48),
                'expires_at' => now()->addDays((int) config('spaces.invitation_expiry_days')),
            ],
        );

        Mail::to($email)->send(new SpaceInvitationMail($invitation));

        return back()->with('success', __('Invitation sent to :email.', ['email' => $email]));
    }

    /**
     * Accept an invitation. The route sits behind auth, so a logged-out invitee
     * is sent to log in (or register with the same email) and returns here.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = SpaceInvitation::query()->where('token', $token)->first();

        if ($invitation === null || $invitation->isAccepted() || $invitation->isExpired()) {
            return redirect()->route('dashboard')->with('error', __('This invitation is no longer valid.'));
        }

        $user = $request->user();

        if (strcasecmp($user->email, $invitation->email) !== 0) {
            return redirect()->route('dashboard')->with('error', __('This invitation was sent to a different email address.'));
        }

        $invitation->space->members()->syncWithoutDetaching([
            $user->id => ['role' => $invitation->role],
        ]);

        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->forceFill(['current_space_id' => $invitation->space_id])->save();

        return redirect()->route('dashboard')->with('success', __('You\'ve joined :space.', [
            'space' => $invitation->space->name,
        ]));
    }

    /**
     * Revoke a pending invitation (owner only).
     */
    public function destroy(Request $request, Space $space, SpaceInvitation $invitation): RedirectResponse
    {
        abort_unless($space->owner_id === $request->user()->id && $invitation->space_id === $space->id, 403);

        $invitation->delete();

        return back()->with('success', __('Invitation revoked.'));
    }
}
