<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PaymentFailedEmail;
use App\Models\Subscription;
use Illuminate\Mail\Mailable;
use Stripe\Subscription as StripeSubscription;

class SendPaymentFailedEmailJob extends SendDunningEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::PaymentFailed;
    }

    protected function buildMail(): Mailable
    {
        return new PaymentFailedEmail($this->user, $this->invoice, $this->step);
    }

    /**
     * Stripe reports a payment that needs the bank's confirmation as a
     * failure too; the confirmation email covers that one, so a card that
     * was never declined is not called declined.
     */
    protected function shouldSend(): bool
    {
        return parent::shouldSend() && $this->invoice->action_required_at === null;
    }

    /**
     * An `incomplete` subscription never started: the reader is still at
     * checkout, which shows the decline itself, and Stripe does not retry it.
     */
    protected function subscriptionAllowsFirstEmail(Subscription $subscription): bool
    {
        return ! in_array($subscription->stripe_status, [
            StripeSubscription::STATUS_INCOMPLETE,
            StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
        ], true);
    }
}
