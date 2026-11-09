<?php

namespace App\Mail\Drip;

/**
 * The note for a user who paid and then asked to end the subscription. Most
 * leave through the Stripe customer portal, where the retention offer already
 * showed them COMEBACK30, so this one carries no discount: repeating the offer
 * here would teach that leaving gets a better price.
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
