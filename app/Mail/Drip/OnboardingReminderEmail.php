<?php

namespace App\Mail\Drip;

class OnboardingReminderEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __('Need Help Getting Started?');
    }

    protected function template(): string
    {
        return 'mail.drip.onboarding-reminder';
    }
}
