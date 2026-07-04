<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\ImportHelpEmail;
use Illuminate\Mail\Mailable;

class SendImportHelpEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::ImportHelp;
    }

    protected function buildMail(): Mailable
    {
        return new ImportHelpEmail($this->user);
    }

    protected function shouldSend(): bool
    {
        return $this->user->isOnboarded()
            && ! $this->user->transactions()->exists()
            && ! $this->user->hasProPlan();
    }
}
