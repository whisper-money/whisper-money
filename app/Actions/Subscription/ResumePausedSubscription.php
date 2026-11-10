<?php

namespace App\Actions\Subscription;

use App\Models\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

/**
 * Takes a pause back before it ends.
 *
 * A pause still to come (the user is in the period they paid for) only loses
 * its `pause_collection`: nothing is charged, and the plan renews on its usual
 * day.
 *
 * Inside the pause the period running now was voided, so handing the plan
 * back for free until the next renewal would make "Resume now" a free month.
 * The billing cycle restarts today instead: the monthly price is charged now,
 * with nothing to prorate (nothing was paid for the voided period), and the
 * plan renews on this day of the month from then on. The payment has to go
 * through for the resume to happen at all (`error_if_incomplete`): a declined
 * card leaves the pause as it was, rather than a resumed plan stuck past due.
 */
class ResumePausedSubscription
{
    /**
     * @throws CardException When the card is declined or needs confirming.
     * @throws ApiErrorException
     */
    public function handle(Subscription $subscription): void
    {
        $options = ['pause_collection' => ''];

        if ($subscription->isPaused()) {
            $options += [
                'billing_cycle_anchor' => 'now',
                'proration_behavior' => 'none',
                'payment_behavior' => 'error_if_incomplete',
            ];
        }

        $subscription->updateStripeSubscription($options);

        $subscription->forceFill([
            'pause_starts_at' => null,
            'pause_resumes_at' => null,
        ])->save();
    }
}
