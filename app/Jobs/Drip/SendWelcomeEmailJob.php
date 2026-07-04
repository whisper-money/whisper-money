<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\WelcomeEmail;
use Illuminate\Mail\Mailable;

class SendWelcomeEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::Welcome;
    }

    protected function buildMail(): Mailable
    {
        return new WelcomeEmail($this->user);
    }
}
