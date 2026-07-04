<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\FeedbackEmail;
use Illuminate\Mail\Mailable;

class SendFeedbackEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::Feedback;
    }

    protected function buildMail(): Mailable
    {
        return new FeedbackEmail($this->user);
    }

    protected function shouldSend(): bool
    {
        return ! $this->user->hasProPlan();
    }
}
