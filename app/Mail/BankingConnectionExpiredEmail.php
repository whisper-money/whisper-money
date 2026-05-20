<?php

namespace App\Mail;

use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class BankingConnectionExpiredEmail extends Mailable implements ShouldQueue
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
        public BankingConnection $bankingConnection,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Action required: reconnect :provider', [
                'provider' => $this->bankingConnection->aspsp_name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.banking-connection-expired',
            with: [
                'userName' => $this->user->name,
                'providerName' => $this->bankingConnection->aspsp_name,
                'reconnectUrl' => route('open-banking.reconnect', $this->bankingConnection),
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
