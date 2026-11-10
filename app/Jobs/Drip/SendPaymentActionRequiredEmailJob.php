<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PaymentActionRequiredEmail;
use Illuminate\Mail\Mailable;

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
}
