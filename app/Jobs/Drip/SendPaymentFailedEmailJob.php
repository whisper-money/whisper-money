<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PaymentFailedEmail;
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
     * Only once Stripe has moved the subscription to `past_due`, the state it
     * retries in. That also keeps the email from a customer whose payment
     * went through before a late failure event arrived, and from an
     * `incomplete` subscription, whose checkout shows the decline itself.
     *
     * @return list<string>
     */
    protected function firstEmailStatuses(): array
    {
        return [StripeSubscription::STATUS_PAST_DUE];
    }
}
