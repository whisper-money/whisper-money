<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

/**
 * The win-back email for a user who paid and then left: it carries the
 * COMEBACK30 offer, so it is marketing and carries the unsubscribe footer.
 */
class SubscriptionCancelledEmail extends DripMail
{
    use MarketingUnsubscribe;

    protected function dripSubject(): string
    {
        return __("We're sorry to see you go");
    }

    protected function template(): string
    {
        return 'mail.drip.subscription-cancelled';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }
}
