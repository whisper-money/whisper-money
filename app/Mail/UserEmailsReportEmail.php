<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserEmailsReportEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $csv,
        public int $userCount,
        public string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Monthly user emails export: {$this->userCount} users",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.user-emails-report',
            with: [
                'userCount' => $this->userCount,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->csv, $this->fileName)
                ->withMime('text/csv'),
        ];
    }
}
