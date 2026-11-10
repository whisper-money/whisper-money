<?php

namespace App\Http\Controllers;

use App\Actions\Subscription\ApplyRetentionCoupon;
use App\Actions\Subscription\CancelAtPeriodEnd;
use App\Actions\Subscription\PauseSubscription;
use App\Actions\Subscription\SwitchToYearly;
use App\Enums\CancellationOffer;
use App\Http\Requests\ContinueCancellationFlowRequest;
use App\Http\Requests\PauseSubscriptionRequest;
use App\Http\Requests\StoreCancellationSurveyResponseRequest;
use App\Models\CancellationSurveyResponse;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\CancellationOffers;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Exceptions\InvalidCoupon;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    /** Longer than any Stripe call a step makes, so a lock never outlives a crash for long. */
    private const STEP_LOCK_SECONDS = 30;

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

        $offers = $this->offers->offersFor($user, $subscription, $request->reason());

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
            // Inside a pause the cancellation ends the plan today.
            'paused' => $subscription->isPaused(),
        ], 201);
    }

    /**
     * Take the retention discount instead of cancelling.
     */
    public function applyRetentionCoupon(ContinueCancellationFlowRequest $request, ApplyRetentionCoupon $applyRetentionCoupon): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->takeStep($request, function (CancellationSurveyResponse $response, Subscription $subscription) use ($user, $applyRetentionCoupon): SymfonyResponse {
            if ($this->offers->retentionCoupon($user, $subscription) === null) {
                return $this->backToBilling(error: __('This offer is no longer available on your plan.'));
            }

            try {
                $applied = $applyRetentionCoupon->handle($user, $subscription);
            } catch (ApiErrorException|InvalidCoupon $exception) {
                report($exception);

                return $this->backToBilling(error: __('We could not update your plan. Please try again in a few minutes.'));
            }

            if (! $applied) {
                return $this->backToBilling(error: __('This offer is no longer available on your plan.'));
            }

            $response->markSaved(CancellationOffer::RetentionCoupon);

            return $this->backToBilling(success: __('Done: your discount is on. Your plan carries on as usual.'));
        });
    }

    /**
     * Move to the yearly plan instead of cancelling.
     */
    public function switchToYearly(ContinueCancellationFlowRequest $request, SwitchToYearly $switchToYearly): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->takeStep($request, function (CancellationSurveyResponse $response, Subscription $subscription) use ($user, $switchToYearly): SymfonyResponse {
            $yearlyPlan = $this->offers->yearlyPlanFor($user, $subscription);

            if ($yearlyPlan === null) {
                return $this->backToBilling(error: __('This offer is no longer available on your plan.'));
            }

            try {
                $switchToYearly->handle($subscription, $yearlyPlan);
            } catch (IncompletePayment $exception) {
                // The swap went through and the invoice waits on the card
                // (3DS): the user did take the offer. Cashier's page, which is
                // not an Inertia page, confirms the payment, so the browser
                // leaves the app for it rather than following an XHR redirect.
                $response->markSaved(CancellationOffer::SwitchToYearly);

                return Inertia::location(route('cashier.payment', [
                    $exception->payment->asStripePaymentIntent()->id,
                    'redirect' => route('settings.billing'),
                ]));
            } catch (ApiErrorException $exception) {
                report($exception);

                return $this->backToBilling(error: __('We could not update your plan. Please try again in a few minutes.'));
            }

            $response->markSaved(CancellationOffer::SwitchToYearly);

            return $this->backToBilling(success: __('You are on the yearly plan now.'));
        });
    }

    /**
     * Pause for a few months instead of cancelling. The plan stays on until
     * the paid period ends, then nothing is charged and there is no plan
     * until the pause ends.
     */
    public function pause(PauseSubscriptionRequest $request, PauseSubscription $pauseSubscription): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->takeStep($request, function (CancellationSurveyResponse $response, Subscription $subscription) use ($user, $request, $pauseSubscription): SymfonyResponse {
            $startsAt = $this->offers->pauseStartsAt($user, $subscription);

            if ($startsAt === null) {
                return $this->backToBilling(error: __('This offer is no longer available on your plan.'));
            }

            try {
                $pauseSubscription->handle($subscription, $startsAt, $request->months());
            } catch (ApiErrorException $exception) {
                report($exception);

                return $this->backToBilling(error: __('We could not update your plan. Please try again in a few minutes.'));
            }

            $response->markSaved(CancellationOffer::Pause);

            return $this->backToBilling(success: __('Done: Standard stays on until :start, then pauses until :end.', [
                'start' => $this->localDate($user, $startsAt),
                'end' => $this->localDate($user, $subscription->pause_resumes_at),
            ]));
        });
    }

    /**
     * Confirm: cancel at the end of the period, never right away. The billing
     * page then shows the scheduled cancellation and the way to take it back.
     */
    public function cancel(ContinueCancellationFlowRequest $request, CancelAtPeriodEnd $cancelAtPeriodEnd): SymfonyResponse
    {
        return $this->takeStep($request, function (CancellationSurveyResponse $response, Subscription $subscription) use ($cancelAtPeriodEnd): SymfonyResponse {
            try {
                $cancelAtPeriodEnd->handle($subscription, $response);
            } catch (ApiErrorException $exception) {
                report($exception);

                return $this->backToBilling(error: __('We could not cancel your subscription. Please try again in a few minutes.'));
            }

            $response->markCanceled();

            return $this->backToBilling();
        });
    }

    /**
     * Run one step that ends the flow, on the open answer in the URL and the
     * subscription it was given for, and never twice at once: two tabs must
     * not both reach Stripe (a yearly switch and a cancellation, say), so the
     * answer is locked and checked again once the lock is held.
     *
     * A subscription that has changed since the answer (cancelled elsewhere,
     * ended, a new one) is not the one the user was answering about.
     *
     * @param  Closure(CancellationSurveyResponse, Subscription): SymfonyResponse  $step
     */
    private function takeStep(ContinueCancellationFlowRequest $request, Closure $step): SymfonyResponse
    {
        $response = $request->surveyResponse();
        $lock = Cache::lock("cancellation-flow:{$response->id}", self::STEP_LOCK_SECONDS);

        abort_unless($lock->get(), 409, 'This cancellation flow is already being handled.');

        try {
            $response->refresh();
            $subscription = $this->offers->cancellableSubscription($request->user());

            abort_if(
                ! $response->isPending()
                    || $subscription === null
                    || $subscription->stripe_id !== $response->stripe_subscription_id,
                409,
                'This subscription can no longer be changed from here.',
            );

            return $step($response, $subscription);
        } finally {
            $lock->release();
        }
    }

    private function localDate(User $user, CarbonInterface $date): string
    {
        return $date->toImmutable()
            ->setTimezone($user->localTimezone())
            ->locale(app()->getLocale())
            ->isoFormat('LL');
    }

    private function backToBilling(?string $error = null, ?string $success = null): RedirectResponse
    {
        $redirect = redirect()->route('settings.billing');

        if ($error !== null) {
            return $redirect->withErrors(['cancellation' => $error]);
        }

        return $success === null ? $redirect : $redirect->with('success', $success);
    }
}
