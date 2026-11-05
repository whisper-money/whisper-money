<?php

namespace App\Mail\Drip;

/**
 * The note for a user who paid and then cancelled the subscription
 * themselves. They were already offered COMEBACK30 in the Stripe customer
 * portal on the way out and turned it down, so this one carries no discount:
 * repeating the offer here would teach that leaving gets a better price.
 */
class CancelledAfterPayingEmail extends WhatWasMissingEmail
{
    protected function intro(): string
    {
        return __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. We saw you cancelled your subscription, and we wanted to ask you personally: what was missing?");
    }

    protected function thanks(): string
    {
        return __('Thank you for the time you spent with Whisper Money, and for supporting it while you did.');
    }
}
