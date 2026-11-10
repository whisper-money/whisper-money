<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\InactiveBankedEmail;
use App\Services\Notifications\WhileAwayRecap;
use Illuminate\Mail\Mailable;

class SendInactiveBankedEmailJob extends SendInactiveUserEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::InactiveBanked;
    }

    protected function buildMail(): Mailable
    {
        $recap = app(WhileAwayRecap::class)->for($this->user, $this->user->last_active_at);

        return new InactiveBankedEmail($this->user, $recap);
    }

    protected function shouldSend(): bool
    {
        return $this->isStillDormant()
            && $this->user->hasActiveSubscriptionOrTrial()
            && $this->user->bankingConnections()->exists();
    }
}
