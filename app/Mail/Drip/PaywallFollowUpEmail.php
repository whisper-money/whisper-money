<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class PaywallFollowUpEmail extends DripMail
{
    use MarketingUnsubscribe;

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
