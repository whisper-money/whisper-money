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
 *
 * Only `To` is filtered, because nothing in `app/Mail` sets `Cc` or `Bcc`. The
 * first mailable that does needs this widened.
 */
class DropSuppressedRecipients
{
    /**
     * Null rather than true when the message is fine: `MessageSending` is
     * dispatched through `Event::until()`, which stops at the first listener to
     * answer with anything, and only `false` cancels the send.
     */
    public function handle(MessageSending $event): ?bool
    {
        $recipients = $event->message->getTo();

        $kept = array_values(array_filter(
            $recipients,
            fn (Address $recipient) => ! SuppressedEmailAddress::isSuppressed($recipient->getAddress()),
        ));

        if (count($kept) === count($recipients)) {
            return null;
        }

        if ($kept === []) {
            return false;
        }

        $event->message->to(...$kept);

        return null;
    }
}
