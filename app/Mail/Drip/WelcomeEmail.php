<?php

namespace App\Mail\Drip;

class WelcomeEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __('Welcome to Whisper Money - Your Privacy-First Finance App');
    }

    protected function template(): string
    {
        return 'mail.drip.welcome';
    }
}
