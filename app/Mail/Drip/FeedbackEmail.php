<?php

namespace App\Mail\Drip;

class FeedbackEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __("How's Your Experience So Far?");
    }

    protected function template(): string
    {
        return 'mail.drip.feedback';
    }
}
