<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class FeedbackEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __("How's Your Experience So Far?");
    }

    protected function template(): string
    {
        return 'mail.drip.feedback';
    }
}
