<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\ExtendTrial;
use App\Http\Requests\ExtendCanceledTrialRequest;
use App\Models\User;
use App\Services\Subscriptions\CanceledTrialExtension;
use App\Services\Subscriptions\CancellationOffers;
use App\Services\Subscriptions\SubscribedPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiErrorException;

/**
 * Where the `trial_ending_canceled` email's "14 more days" link lands: the
 * page says what taking it does (the new trial end, the plan back on, the
 * first charge on that day) and a button confirms it. The link itself
 * changes nothing, so a mail scanner opening it is harmless.
 */
class TrialExtensionController extends Controller
{
    public function __construct(
        private CanceledTrialExtension $extension,
        private ExtendTrial $extendTrial,
    ) {}

    /**
     * Never asks Stripe: only what is stored here decides whether it is shown
     * (`CanceledTrialExtension`). When it no longer applies (taken already,
     * the trial over, the plan back on) the page says so, and still offers to
     * keep the plan while it is set to cancel.
     */
    public function show(Request $request, SubscribedPlan $subscribedPlan): Response|RedirectResponse
    {
        if (! config('subscriptions.enabled')) {
            return redirect()->route('dashboard');
        }

        /** @var User $user */
        $user = $request->user();
        $subscription = $this->extension->subscriptionFor($user);
        $current = $user->subscription('default');

        return Inertia::render('subscription/extend-trial', [
            'extension' => $subscription === null ? null : [
                'days' => ExtendTrial::DAYS,
                'trialEndsAt' => $this->extendTrial->extendedTrialEndsAt($subscription)->toIso8601String(),
                'plan' => $subscribedPlan->for($user, $subscription),
            ],
            'canKeepPlan' => $current !== null && $current->onGracePeriod() && ! $user->cannotUseStripe(),
        ]);
    }

    /**
     * Extend the trial and take back the cancellation, in one Stripe update.
     * Stripe is asked here, before anything changes, whether the subscription
     * carries a discount or a pause (`CancellationOffers::extendTrial()`).
     */
    public function store(ExtendCanceledTrialRequest $request, CancellationOffers $offers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $this->extension->subscriptionFor($user);

        if ($subscription === null || $offers->extendTrial($user, $subscription) === null) {
            return $this->refused(__('This offer is no longer available on your plan.'));
        }

        try {
            $trialEndsAt = $this->extendTrial->handle($user, $subscription, reactivate: true);
        } catch (ApiErrorException $exception) {
            report($exception);

            return $this->refused(__('We could not update your plan. Please try again in a few minutes.'));
        }

        if ($trialEndsAt === null) {
            return $this->refused(__('This offer is no longer available on your plan.'));
        }

        return redirect()->route('settings.billing')->with('success', __('Done: your trial now ends on :date and your plan is back on.', [
            'date' => $user->localDate($trialEndsAt),
        ]));
    }

    private function refused(string $error): RedirectResponse
    {
        return redirect()->route('settings.billing.extend-trial')->withErrors(['trial_extension' => $error]);
    }
}
