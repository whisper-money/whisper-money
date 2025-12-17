<?php

namespace App\Mail\Drip;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeedbackEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "How's Your Experience So Far?",
        )->from(config('mail.from.address', 'hello@example.com'), 'Victor');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.drip.feedback',
            with: [
                'userName' => $this->user->name,
            ],
        );
    }
}
