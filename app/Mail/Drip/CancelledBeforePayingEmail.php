<?php

namespace App\Mail\Drip;

/**
 * The note for a user whose subscription ended before the first charge (a
 * cancelled trial, a first payment that never went through).
 */
class CancelledBeforePayingEmail extends WhatWasMissingEmail
{
    protected function intro(): string
    {
        return __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. We saw your subscription ended before your first payment, and we wanted to ask you personally: what was missing?");
    }

    protected function thanks(): string
    {
        return __('Thank you for giving Whisper Money a try.');
    }
}
