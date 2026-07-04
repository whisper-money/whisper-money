<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\SubscriptionCancelledEmail;
use Illuminate\Mail\Mailable;

class SendSubscriptionCancelledEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::SubscriptionCancelled;
    }

    protected function buildMail(): Mailable
    {
        return new SubscriptionCancelledEmail($this->user);
    }
}
