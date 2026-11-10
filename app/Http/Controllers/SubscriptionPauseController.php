<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\ResumePausedSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

/**
 * "Resume now" on a paused plan, from the billing page and the paused screen.
 */
class SubscriptionPauseController extends Controller
{
    /**
     * Take the pause back. Inside it, the plan is charged today and comes back
     * at once; a pause still to come is simply dropped (`ResumePausedSubscription`).
     */
    public function resume(Request $request, ResumePausedSubscription $resumePausedSubscription): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $user->subscription('default');

        if (! $subscription instanceof Subscription || ! $subscription->hasPause() || $user->cannotUseStripe()) {
            return redirect()->route('settings.billing')
                ->withErrors(['pause' => __('There is no pause to undo on this plan.')]);
        }

        $wasPaused = $subscription->isPaused();

        try {
            $resumePausedSubscription->handle($subscription);
        } catch (CardException) {
            return redirect()->route('settings.billing')
                ->withErrors(['pause' => __('We could not charge your card, so your plan is still paused. Update your payment method and try again.')]);
        } catch (ApiErrorException $exception) {
            report($exception);

            return redirect()->route('settings.billing')
                ->withErrors(['pause' => __('We could not resume your plan. Please try again in a few minutes.')]);
        }

        return redirect()->route('settings.billing')->with('success', $wasPaused
            ? __('Welcome back: Standard is on again.')
            : __('Your plan will not pause. It renews as usual.'));
    }
}
