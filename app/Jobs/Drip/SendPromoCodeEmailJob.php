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

    protected function shouldSend(): bool
    {
        return $this->user->isOnboarded()
            && $this->user->transactions()->exists()
            && ! $this->user->hasProPlan();
    }
}
