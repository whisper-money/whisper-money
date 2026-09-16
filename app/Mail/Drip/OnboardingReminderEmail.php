<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class OnboardingReminderEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __('Need Help Getting Started?');
    }

    protected function template(): string
    {
        return 'mail.drip.onboarding-reminder';
    }
}
