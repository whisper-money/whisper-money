<?php

namespace App\Mail\Drip;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingReminderEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Need Help Getting Started?',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.drip.onboarding-reminder',
            with: [
                'userName' => $this->user->name,
            ],
        );
    }
}
