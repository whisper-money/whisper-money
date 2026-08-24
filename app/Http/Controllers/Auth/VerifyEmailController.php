<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Verify the user's email from a signed link regardless of authentication state.
     *
     * The link is protected by the `signed` middleware (signature + expiration), so the
     * recipient does not need an active session for verification to succeed. This lets the
     * link work when it opens in a browser where the user is not logged in.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->user()?->is($user)) {
            return redirect()->intended(route('dashboard').'?verified=1');
        }

        return redirect()->route('login')->with(
            'status',
            __('Your email has been verified. You can now sign in.'),
        );
    }
}
