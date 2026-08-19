<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the owners which banks have stopped working for everyone who uses them,
 * and hands over the exact command that notifies those users.
 *
 * Internal: this goes to REPORT_RECIPIENTS, never to a customer, which is why
 * nothing in it is translated.
 */
class BankHealthAlertEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $banks
     * @param  int  $repeatAfterDays  Days before the same bank is reported again
     * @param  bool  $looksLikeProviderOutage  Too many banks at once to blame the banks
     */
    public function __construct(
        public array $banks,
        public int $repeatAfterDays,
        public bool $looksLikeProviderOutage = false,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->banks);

        return new Envelope(
            subject: $count === 1
                ? "Bank connection alert: {$this->banks[0]['display_name']} is broken for everyone"
                : "Bank connection alert: {$count} banks are broken for everyone",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.bank-health-alert',
            with: [
                'banks' => $this->banks,
                'repeatAfterDays' => $this->repeatAfterDays,
                'looksLikeProviderOutage' => $this->looksLikeProviderOutage,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
