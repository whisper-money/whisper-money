<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class ImportHelpEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __("Let's Import Your Transactions");
    }

    protected function template(): string
    {
        return 'mail.drip.import-help';
    }
}
