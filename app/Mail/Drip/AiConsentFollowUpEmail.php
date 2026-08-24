<?php

namespace App\Mail\Drip;

class AiConsentFollowUpEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __('Putting AI to work on your finances');
    }

    protected function template(): string
    {
        return 'mail.drip.ai-consent-follow-up';
    }
}
