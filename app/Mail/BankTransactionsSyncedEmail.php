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

class BankTransactionsSyncedEmail extends Mailable implements ShouldQueue
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

    /**
     * @param  array<string, int>  $transactionsPerBank
     */
    public function __construct(
        public User $user,
        public int $totalTransactions,
        public array $transactionsPerBank,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'no-reply@whisper.money'),
                config('mail.from.name', 'Whisper Money'),
            ),
            subject: __(':count new transactions synced on Whisper Money', ['count' => $this->totalTransactions]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.bank-transactions-synced',
            with: [
                'userName' => $this->user->name,
                'transactionsPerBank' => $this->transactionsPerBank,
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
