<?php

namespace App\Mail\Drip;

class InactiveNoBankEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __('Your accounts have not been updated in a week');
    }

    protected function template(): string
    {
        return 'mail.drip.inactive-no-bank';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }
}
