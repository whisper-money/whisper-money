<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

class PromoCodeEmail extends DripMail
{
    use MarketingUnsubscribe;

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
