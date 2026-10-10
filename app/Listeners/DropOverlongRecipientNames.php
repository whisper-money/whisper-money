<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\MailboxHeader;
use Symfony\Component\Mime\Header\MailboxListHeader;

/**
 * SES rejects the whole send when a recipient is longer than 320 characters,
 * and it measures the encoded `Name <email>` form. `Mail::to($user)` puts the
 * user's name there, so a long non-ASCII name, which MIME Q-encodes into a run
 * of `=?utf-8?Q?...?=` words, is enough to cross the limit and that user never
 * gets any email. Dropping the name for those recipients keeps the send going.
 *
 * Deliberately not queued: it has to rewrite the message before it leaves.
 */
class DropOverlongRecipientNames
{
    /**
     * The longest address SES accepts.
     */
    private const int MAX_ADDRESS_LENGTH = 320;

    /**
     * Always null: `MessageSending` is dispatched through `Event::until()`,
     * which stops at the first listener to answer with anything, and this one
     * must never keep `DropSuppressedRecipients` from running.
     */
    public function handle(MessageSending $event): ?bool
    {
        foreach (['To', 'Cc', 'Bcc'] as $name) {
            $header = $event->message->getHeaders()->get($name);

            if ($header instanceof MailboxListHeader) {
                $header->setAddresses(array_map($this->shorten(...), $header->getAddresses()));
            }
        }

        return null;
    }

    private function shorten(Address $address): Address
    {
        return $this->isTooLong($address) ? new Address($address->getAddress()) : $address;
    }

    /**
     * Measures the address as it goes out in the header, with the name encoded.
     */
    private function isTooLong(Address $address): bool
    {
        return strlen((new MailboxHeader('To', $address))->getBodyAsString()) > self::MAX_ADDRESS_LENGTH;
    }
}
