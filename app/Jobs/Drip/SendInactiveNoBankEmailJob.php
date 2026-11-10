<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\InactiveNoBankEmail;
use Illuminate\Mail\Mailable;

class SendInactiveNoBankEmailJob extends SendInactiveUserEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::InactiveNoBank;
    }

    protected function buildMail(): Mailable
    {
        return new InactiveNoBankEmail($this->user);
    }

    protected function shouldSend(): bool
    {
        return $this->isStillDormant()
            && $this->user->wantsInactiveNoBankEmail()
            && ! $this->user->bankingConnections()->exists();
    }
}
