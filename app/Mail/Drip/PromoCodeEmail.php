<?php

namespace App\Mail\Drip;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class PromoCodeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Founder Discount - First Month for $1',
        )->from(config('mail.from.address', 'hello@example.com'), 'Victor');
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

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('emails', releaseAfter: 1)];
    }
}
