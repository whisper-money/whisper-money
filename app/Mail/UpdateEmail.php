<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class UpdateEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

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

    public function __construct(
        public User $user,
        public string $viewName,
        public string $emailSubject = 'Update from Whisper Money'
    ) {
        $this->onQueue('emails');
    }

    /**
     * The subject doubles as a translation key. Laravel hydrates the envelope
     * inside withLocale(), so it resolves in the recipient's locale, and an
     * untranslated subject falls back to the string itself, leaving plain
     * --subject values working unchanged.
     *
     * Reply-to is the shared founders inbox rather than the MAIL_FROM_ADDRESS
     * of the moment, because update emails invite people to write back.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __($this->emailSubject),
            replyTo: [new Address(
                config('mail.drip_from.address', 'hi@whisper.money'),
                config('mail.drip_from.name', 'Whisper Money'),
            )],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: "mail.updates.{$this->viewName}",
            with: [
                'user' => $this->user,
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
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
