<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PaymentActionRequiredEmail;
use Illuminate\Mail\Mailable;
use Stripe\Subscription as StripeSubscription;

class SendPaymentActionRequiredEmailJob extends SendDunningEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::PaymentActionRequired;
    }

    protected function buildMail(): Mailable
    {
        return new PaymentActionRequiredEmail($this->user, $this->invoice, $this->step);
    }

    /**
     * A renewal held by the bank leaves the subscription `past_due`; a first
     * payment held outside checkout leaves it `incomplete`, and either way
     * only the customer can release it.
     *
     * @return list<string>
     */
    protected function firstEmailStatuses(): array
    {
        return [StripeSubscription::STATUS_PAST_DUE, StripeSubscription::STATUS_INCOMPLETE];
    }
}
