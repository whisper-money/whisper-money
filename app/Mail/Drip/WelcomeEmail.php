<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class WelcomeEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __('Welcome to Whisper Money - Your Privacy-First Finance App');
    }

    protected function template(): string
    {
        return 'mail.drip.welcome';
    }
}
