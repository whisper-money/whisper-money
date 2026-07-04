<?php

namespace App\Mail\Drip;

class PaywallFollowUpEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __('What stopped you from getting started?');
    }

    protected function template(): string
    {
        return 'mail.drip.paywall-follow-up';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }
}
