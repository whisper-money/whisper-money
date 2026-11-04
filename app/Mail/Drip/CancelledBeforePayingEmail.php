<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

/**
 * The founders' note for a user whose subscription ended before the first
 * charge: no offer, just "what was missing?" and an invitation to reply.
 * It shares {@see SubscriptionCancelledEmail}'s type, so it is marketing too.
 */
class CancelledBeforePayingEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __('What was missing?');
    }

    protected function template(): string
    {
        return 'mail.drip.cancelled-before-paying';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }
}
