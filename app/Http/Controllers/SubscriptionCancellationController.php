<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\ApplyRetentionCoupon;
use App\Actions\Subscription\CancelAtPeriodEnd;
use App\Actions\Subscription\SwitchToYearly;
use App\Enums\CancellationOffer;
use App\Http\Requests\ContinueCancellationFlowRequest;
use App\Http\Requests\StoreCancellationSurveyResponseRequest;
use App\Models\CancellationSurveyResponse;
use App\Models\User;
use App\Services\Subscriptions\CancellationOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\InvalidCoupon;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * The in-app cancel flow on the billing page: why the user is leaving, one
 * offer matched to that (and at most one fallback), then a cancellation at the
 * end of the period. Every run is kept as a `CancellationSurveyResponse`.
 *
 * Only ever the signed-in user's own `default` subscription, and only while it
 * is running and not already set to cancel (`CancellationOffers::cancellableSubscription()`).
 */
class SubscriptionCancellationController extends Controller
{
    public function __construct(private CancellationOffers $offers) {}

    /**
     * Store the reason and answer with what comes next: the offers for that
     * reason and the day the plan would stop. A JSON
     * answer, not a redirect: the flow stays open in its dialog.
     */
    public function store(StoreCancellationSurveyResponseRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $this->offers->cancellableSubscription($user);

        abort_if($subscription === null, 409, 'There is no subscription to cancel on this account.');

        $offers = $this->offers->for($user, $subscription, $request->reason());

        $response = $user->cancellationSurveyResponses()->create([
            'stripe_subscription_id' => $subscription->stripe_id,
            'reason' => $request->reason(),
            'details' => $request->details(),
            'offer_shown' => $offers[0]['offer'] ?? null,
            'on_trial' => $subscription->onTrial(),
        ]);

        return response()->json([
            'id' => $response->id,
            'offers' => $offers,
            'endsAt' => $this->offers->endsAt($subscription),
            'onTrial' => $subscription->onTrial(),
        ], 201);
    }

    /**
     * Take the retention discount instead of cancelling.
     */
    public function applyRetentionCoupon(ContinueCancellationFlowRequest $request, ApplyRetentionCoupon $applyRetentionCoupon): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$response, $subscription] = $this->openFlow($request);

        if ($this->offers->retentionCoupon($user, $subscription) === null) {
            return $this->offerUnavailable();
        }

        try {
            $applied = $applyRetentionCoupon->handle($user, $subscription);
        } catch (ApiErrorException|InvalidCoupon $exception) {
            report($exception);

            return $this->offerFailed();
        }

        if (! $applied) {
            return $this->offerUnavailable();
        }

        $response->markSaved(CancellationOffer::RetentionCoupon);

        return redirect()->route('settings.billing')
            ->with('success', __('Done: your discount is on. Your plan carries on as usual.'));
    }

    /**
     * Move to the yearly plan instead of cancelling.
     */
    public function switchToYearly(ContinueCancellationFlowRequest $request, SwitchToYearly $switchToYearly): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$response, $subscription] = $this->openFlow($request);
        $yearlyPlan = $this->offers->yearlyPlanFor($user, $subscription);

        if ($yearlyPlan === null) {
            return $this->offerUnavailable();
        }

        try {
            $switchToYearly->handle($subscription, $yearlyPlan);
        } catch (IncompletePayment $exception) {
            // The swap went through and the invoice waits on the card (3DS):
            // the user did take the offer. Cashier's page confirms the payment.
            $response->markSaved(CancellationOffer::SwitchToYearly);

            return redirect()->route('cashier.payment', [
                $exception->payment->asStripePaymentIntent()->id,
                'redirect' => route('settings.billing'),
            ]);
        } catch (ApiErrorException $exception) {
            report($exception);

            return $this->offerFailed();
        }

        $response->markSaved(CancellationOffer::SwitchToYearly);

        return redirect()->route('settings.billing')
            ->with('success', __('You are on the yearly plan now.'));
    }

    /**
     * Confirm: cancel at the end of the period, never right away. The billing
     * page then shows the scheduled cancellation and the way to take it back.
     */
    public function cancel(ContinueCancellationFlowRequest $request, CancelAtPeriodEnd $cancelAtPeriodEnd): RedirectResponse
    {
        [$response, $subscription] = $this->openFlow($request);

        try {
            $cancelAtPeriodEnd->handle($subscription, $response);
        } catch (ApiErrorException $exception) {
            report($exception);

            return redirect()->route('settings.billing')
                ->withErrors(['cancellation' => __('We could not cancel your subscription. Please try again in a few minutes.')]);
        }

        $response->markCanceled();

        return redirect()->route('settings.billing');
    }

    /**
     * The open answer in the URL and the subscription it was given for. A
     * subscription that has changed since (cancelled elsewhere, ended, a new
     * one) is not the one the user was answering about.
     *
     * @return array{CancellationSurveyResponse, Subscription}
     */
    private function openFlow(ContinueCancellationFlowRequest $request): array
    {
        $response = $request->surveyResponse();
        $subscription = $this->offers->cancellableSubscription($request->user());

        abort_if(
            $subscription === null || $subscription->stripe_id !== $response->stripe_subscription_id,
            409,
            'This subscription can no longer be changed from here.',
        );

        return [$response, $subscription];
    }

    private function offerUnavailable(): RedirectResponse
    {
        return redirect()->route('settings.billing')
            ->withErrors(['cancellation' => __('This offer is no longer available on your plan.')]);
    }

    private function offerFailed(): RedirectResponse
    {
        return redirect()->route('settings.billing')
            ->withErrors(['cancellation' => __('We could not update your plan. Please try again in a few minutes.')]);
    }
}
