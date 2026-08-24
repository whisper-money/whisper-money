<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PaywallFollowUpEmail;
use Illuminate\Mail\Mailable;

class SendPaywallFollowUpEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::PaywallFollowUp;
    }

    protected function buildMail(): Mailable
    {
        return new PaywallFollowUpEmail($this->user);
    }

    protected function shouldSend(): bool
    {
        return ! $this->user->hasProPlan()
            && $this->user->bankingConnections()->exists();
    }
}
