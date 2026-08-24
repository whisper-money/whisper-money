<?php

namespace App\Mail\Drip;

class ImportHelpEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __("Let's Import Your Transactions");
    }

    protected function template(): string
    {
        return 'mail.drip.import-help';
    }
}
