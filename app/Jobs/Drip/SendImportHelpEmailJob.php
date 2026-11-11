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

    /**
     * For a user who has not brought any real movements in yet. Onboarding
     * estimates do not count: a user with only those still needs the help.
     */
    protected function shouldSend(): bool
    {
        return $this->user->isOnboarded()
            && ! $this->user->transactions()->withoutEstimates()->exists()
            && $this->user->isOnFreePlan();
    }
}
