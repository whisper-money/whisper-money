<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\OnboardingReminderEmail;
use Illuminate\Mail\Mailable;

class SendOnboardingReminderEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::OnboardingReminder;
    }

    protected function buildMail(): Mailable
    {
        return new OnboardingReminderEmail($this->user);
    }

    protected function shouldSend(): bool
    {
        return ! $this->user->isOnboarded();
    }
}
