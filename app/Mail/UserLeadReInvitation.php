<?php

namespace App\Mail;

use App\Models\UserLead;
use App\Services\LandingAuthOverrideService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class UserLeadReInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    public function __construct(public UserLead $lead)
    {
        $this->onQueue('emails');
        $this->locale($lead->preferredLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Still want to try Whisper Money?'),
        );
    }

    public function content(): Content
    {
        $signupUrl = app(LandingAuthOverrideService::class)
            ->generateInvitationUrl($this->lead->id, days: 30);

        return new Content(
            markdown: 'mail.user-lead-re-invitation',
            with: [
                'lead' => $this->lead,
                'signupUrl' => $signupUrl,
                'promoCodeMonthly' => $this->lead->promo_code_monthly,
                'promoCodeYearly' => $this->lead->promo_code_yearly,
            ],
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
