<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\AiConsentFollowUpEmail;
use Illuminate\Mail\Mailable;

class SendAiConsentFollowUpEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::AiConsentFollowUp;
    }

    protected function buildMail(): Mailable
    {
        return new AiConsentFollowUpEmail($this->user);
    }

    protected function shouldSend(): bool
    {
        return $this->user->aiConsents()->active()->exists();
    }
}
