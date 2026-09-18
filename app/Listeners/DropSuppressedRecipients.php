<?php

namespace App\Listeners;

use App\Models\SuppressedEmailAddress;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * The safety net under every send. `User::canReceiveEmails()` covers the queued
 * senders, but most bounces come from addresses typo'd at registration, and that
 * mail (verification, password reset) goes out through Fortify and never asks.
 *
 * Deliberately not queued: a queued listener cannot cancel the send, and
 * returning false here is the whole point.
 */
class DropSuppressedRecipients
{
    public function handle(MessageSending $event): bool
    {
        $recipients = $event->message->getTo();

        $kept = array_values(array_filter(
            $recipients,
            fn (Address $recipient) => ! SuppressedEmailAddress::isSuppressed($recipient->getAddress()),
        ));

        if (count($kept) === count($recipients)) {
            return true;
        }

        if ($kept === []) {
            return false;
        }

        $event->message->to(...$kept);

        return true;
    }
}
