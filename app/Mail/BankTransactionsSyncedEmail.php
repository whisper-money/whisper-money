<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class BankTransactionsSyncedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [2, 5, 10, 30];

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
            subject: __(':count new transactions synced on Whisper Money', ['count' => $this->totalTransactions]),
        )->from(config('mail.from.address', 'hello@example.com'), 'Victor');
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

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
