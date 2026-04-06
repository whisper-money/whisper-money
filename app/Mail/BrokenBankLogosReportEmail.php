<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BrokenBankLogosReportEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{id: string, name: string, previous_logo: string}>  $updatedBanks
     */
    public function __construct(
        public array $updatedBanks,
    ) {}

    public function envelope(): Envelope
    {
        $updatedCount = count($this->updatedBanks);

        return new Envelope(
            subject: trans_choice('Weekly bank logo audit: :count broken logo|Weekly bank logo audit: :count broken logos', $updatedCount, ['count' => $updatedCount]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.broken-bank-logos-report',
            with: [
                'updatedBanks' => $this->updatedBanks,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
