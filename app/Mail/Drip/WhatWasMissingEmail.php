<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;

/**
 * The founders' "what was missing?" note for a user whose subscription
 * ended, with no offer, just an invitation to reply. The variants differ only
 * in how they open and how they thank the reader. It shares
 * {@see SubscriptionCancelledEmail}'s type, so it is marketing too.
 */
abstract class WhatWasMissingEmail extends DripMail
{
    use MarketingUnsubscribe;

    /**
     * The opening paragraph, saying what we saw happen to the subscription.
     */
    abstract protected function intro(): string;

    /**
     * The closing thanks, before the signature.
     */
    abstract protected function thanks(): string;

    protected function dripSubject(): string
    {
        return __('What was missing?');
    }

    protected function template(): string
    {
        return 'mail.drip.what-was-missing';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * @return array{intro: string, thanks: string}
     */
    protected function contentData(): array
    {
        return [
            'intro' => $this->intro(),
            'thanks' => $this->thanks(),
        ];
    }
}
