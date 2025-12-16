<?php

namespace App\Mail\Drip;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PromoCodeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Founder Discount - First Month for $1',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.drip.promo-code',
            with: [
                'userName' => $this->user->name,
                'promoCode' => 'FOUNDER',
            ],
        );
    }
}
