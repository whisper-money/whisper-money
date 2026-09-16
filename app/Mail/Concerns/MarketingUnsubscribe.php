<?php

namespace App\Mail\Concerns;

use App\Models\User;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * The unsubscribe half of a marketing email: the signed link its footer points
 * at, and the header that lets a mailbox offer the same thing without opening
 * the message.
 *
 * Both halves matter. The header is what Gmail and Apple Mail read to draw
 * their own "Unsubscribe" button, and a sender that honours one click there is
 * a sender whose next campaign lands in the inbox rather than in Promotions.
 *
 * Every mailable whose type is in `DripEmailType::marketing()` uses this, and a
 * trait rather than a base class because `UpdateEmail` is not a `DripMail` and
 * needs exactly the same two things.
 *
 * @property User $user
 */
trait MarketingUnsubscribe
{
    /**
     * A one-click unsubscribe header, so a reader who has had enough can act
     * from the mailbox and the provider keeps delivering to everyone else.
     */
    public function headers(): Headers
    {
        return $this->marketingHeaders();
    }

    protected function marketingHeaders(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->marketingUnsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * Signed, and pointed at a route that fixes which preference it switches
     * off, so the link can never be edited into turning something else off.
     */
    protected function marketingUnsubscribeUrl(): string
    {
        return URL::signedRoute('marketing.unsubscribe', ['user' => $this->user->id]);
    }
}
