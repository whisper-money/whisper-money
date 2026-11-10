<?php

namespace App\Actions\Subscription;

use App\Models\CancellationSurveyResponse;
use App\Models\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * Cancels a subscription the way the cancel flow promises: nothing stops
 * today, the plan runs to the end of the trial or of the paid period, and it
 * can be taken back until then (`ResumeSubscription`).
 *
 * Inside a pause there is no plan left to run and the period running now was
 * voided, so the subscription ends today instead: waiting for the period end
 * would only leave a paused subscription that is set to cancel, which neither
 * resuming nor keeping the plan can sensibly undo.
 *
 * The reason is copied onto Stripe's `cancellation_details` too, so the
 * feedback Stripe holds stays comparable with what its portal collected.
 */
class CancelAtPeriodEnd
{
    /**
     * @throws ApiErrorException When Stripe refuses the cancellation.
     */
    public function handle(Subscription $subscription, CancellationSurveyResponse $response): void
    {
        if ($subscription->isPaused()) {
            // Stripe refuses to update a subscription once it has ended, so
            // the reason goes on first.
            $this->recordReason($subscription, $response);
            $subscription->cancelNow();

            return;
        }

        $subscription->cancel();
        $this->recordReason($subscription, $response);
    }

    /**
     * Only bookkeeping: a failure here is reported, never shown to the user.
     */
    private function recordReason(Subscription $subscription, CancellationSurveyResponse $response): void
    {
        try {
            $subscription->updateStripeSubscription([
                'cancellation_details' => [
                    'feedback' => $response->reason->stripeFeedback(),
                    // Our own reason, finer than Stripe's feedback values. The
                    // user's own words stay in our database: they are never
                    // sent to a third party.
                    'comment' => $response->reason->value,
                ],
            ]);
        } catch (ApiErrorException $exception) {
            report($exception);
        }
    }
}
