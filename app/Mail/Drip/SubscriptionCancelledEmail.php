<?php

namespace App\Mail\Drip;

class SubscriptionCancelledEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __("We're sorry to see you go");
    }

    protected function template(): string
    {
        return 'mail.drip.subscription-cancelled';
    }
}
