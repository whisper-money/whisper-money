<?php

namespace App\Mail\Drip;

class PromoCodeEmail extends DripMail
{
    protected function dripSubject(): string
    {
        return __('Your Founder Discount - 80% Off First Period');
    }

    protected function template(): string
    {
        return 'mail.drip.promo-code';
    }

    protected function contentData(): array
    {
        return ['promoCode' => 'FOUNDER'];
    }
}
