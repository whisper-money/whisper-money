<?php

namespace App\Mail;

use App\Models\UserLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class WaitlistWelcome extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Delete the job if its models no longer exist (e.g. lead was cleaned up after DDoS).
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public $backoff = [2, 5, 10, 30];

    /**
     * Create a new message instance.
     */
    public function __construct(public UserLead $lead)
    {
        $this->onQueue('emails');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'no-reply@whisper.money'),
                config('mail.from.name', 'Whisper Money'),
            ),
            subject: __("You're on the Whisper Money waiting list!"),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.waitlist-welcome',
            with: [
                'position' => $this->lead->position,
                'referralUrl' => $this->lead->referral_url,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
