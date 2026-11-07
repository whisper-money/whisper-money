<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * The link someone on a phone asked for, to finish importing their history
 * from a computer.
 */
class OnboardingImportLinkEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** How long the link keeps working. */
    private const LINK_LIFETIME_DAYS = 7;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    public function __construct(
        public User $user,
        public Account $account,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Import your history from your computer'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.onboarding-import-link',
            with: [
                'userName' => $this->user->name,
                'url' => URL::temporarySignedRoute(
                    'onboarding.import-link.show',
                    now()->addDays(self::LINK_LIFETIME_DAYS),
                    ['account' => $this->account->id],
                ),
                'days' => self::LINK_LIFETIME_DAYS,
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
