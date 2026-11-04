<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Subscription\CancelSubscriptionNow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;
use Stripe\Exception\ApiErrorException;

class ProfileController extends Controller
{
    /**
     * Show the combined account settings page.
     */
    public function account(Request $request): Response
    {
        return Inertia::render('settings/account', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'twoFactorEnabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
        ]);
    }

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/account', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'twoFactorEnabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'requiresConfirmation' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $request->user()->fill($data);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('account.edit');
    }

    /**
     * Delete the user's account. A trial or a paid-up plan has to be cancelled
     * by the user first; an unpaid subscription is cancelled here, with it.
     */
    public function destroy(Request $request, CancelSubscriptionNow $cancelSubscriptionNow): RedirectResponse
    {
        $user = $request->user();

        if ($user->mustCancelSubscriptionBeforeDeleting()) {
            return back()->withErrors([
                'subscription' => __('Please cancel your subscription before deleting your account.'),
            ]);
        }

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $unpaidSubscription = $user->unpaidSubscription();

        if ($unpaidSubscription !== null) {
            try {
                $cancelSubscriptionNow->handle($user, $unpaidSubscription);
            } catch (ApiErrorException $exception) {
                report($exception);

                return back()->withErrors([
                    'subscription' => __('We could not cancel your subscription. Please try again in a few minutes.'),
                ]);
            }
        }

        Auth::logout();

        $user->markAsDeleted();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
