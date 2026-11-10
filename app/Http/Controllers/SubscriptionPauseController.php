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
 * "Resume now" on a paused plan, from the billing page and from the paused
 * screen every gated page leads to. Its own controller rather than
 * `SubscriptionController::resume()`, which takes back a scheduled
 * cancellation, a different thing with a different Stripe call.
 */
class SubscriptionPauseController extends Controller
{
    /**
     * Take the pause back. Inside it, the plan is charged today and comes back
     * at once; a pause still to come is simply dropped (`ResumePausedSubscription`).
     * Back to the page it was pressed on either way: from the paused screen,
     * a plan that is on again moves on to the dashboard by itself.
     */
    public function resume(Request $request, ResumePausedSubscription $resumePausedSubscription): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $user->subscription('default');

        if (! $subscription instanceof Subscription || ! $subscription->hasPause() || $user->cannotUseStripe()) {
            return $this->backWithError(__('There is no pause to undo on this plan.'));
        }

        $wasPaused = $subscription->isPaused();

        try {
            $resumePausedSubscription->handle($subscription);
        } catch (CardException) {
            return $this->backWithError(__('We could not take the payment, so your plan is still paused. Check your payment method under Manage Subscription and try again.'));
        } catch (ApiErrorException $exception) {
            report($exception);

            return $this->backWithError(__('We could not resume your plan. Please try again in a few minutes.'));
        }

        return back()->with('success', $wasPaused
            ? __('Welcome back: Standard is on again.')
            : __('Your plan will not pause. It renews as usual.'));
    }

    private function backWithError(string $message): RedirectResponse
    {
        return back()->withErrors(['pause' => $message]);
    }
}
