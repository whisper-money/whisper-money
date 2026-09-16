<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class AiConsentFollowUpEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __('Putting AI to work on your finances');
    }

    protected function template(): string
    {
        return 'mail.drip.ai-consent-follow-up';
    }
}
