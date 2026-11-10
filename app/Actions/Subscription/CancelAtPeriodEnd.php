<?php

namespace App\Actions\Subscription;

use App\Models\CancellationSurveyResponse;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiErrorException;

/**
 * Cancels a subscription the way the cancel flow promises: nothing stops
 * today, the plan runs to the end of the trial or of the paid period, and it
 * can be taken back until then (`ResumeSubscription`).
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
        $subscription->cancel();

        // Only bookkeeping, and the cancellation has already gone through: a
        // failure here is reported, never shown to the user.
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
