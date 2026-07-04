<?php

namespace App\Mail\Drip;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

abstract class DripMail extends Mailable implements ShouldQueue
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

    public function __construct(public User $user)
    {
        $this->onQueue('emails');
    }

    abstract protected function dripSubject(): string;

    abstract protected function template(): string;

    /**
     * Extra view data merged alongside the recipient's name.
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [];
    }

    /**
     * Whether replies should route back to the drip sender address.
     */
    protected function repliesToSender(): bool
    {
        return false;
    }

    public function envelope(): Envelope
    {
        $from = new Address(
            config('mail.drip_from.address', 'hi@whisper.money'),
            config('mail.drip_from.name', 'Álvaro and Víctor'),
        );

        return new Envelope(
            from: $from,
            replyTo: $this->repliesToSender() ? [$from] : [],
            subject: $this->dripSubject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->template(),
            with: [
                'userName' => $this->user->name,
                ...$this->contentData(),
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
