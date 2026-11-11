<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PromoCodeEmail;
use Illuminate\Mail\Mailable;

class SendPromoCodeEmailJob extends SendDripEmailJob
{
    protected function emailType(): DripEmailType
    {
        return DripEmailType::PromoCode;
    }

    protected function buildMail(): Mailable
    {
        return new PromoCodeEmail($this->user);
    }

    /**
     * Only for a user with real movements in the app. Onboarding estimates do
     * not count: they are round guesses, not data the paid plan works on.
     */
    protected function shouldSend(): bool
    {
        return $this->user->isOnboarded()
            && $this->user->transactions()->withoutEstimates()->exists()
            && $this->user->isOnFreePlan();
    }
}
