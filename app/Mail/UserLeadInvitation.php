<?php

namespace App\Mail;

use App\Enums\LeadCohort;
use App\Models\UserLead;
use App\Services\LandingAuthOverrideService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class UserLeadInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    public function __construct(public UserLead $lead, public LeadCohort $cohort)
    {
        $this->onQueue('emails');
        $this->locale($lead->preferredLocale());
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectFor($this->cohort),
        );
    }

    public function content(): Content
    {
        $signupUrl = app(LandingAuthOverrideService::class)
            ->generateInvitationUrl($this->lead->id, days: 30);

        return new Content(
            markdown: $this->viewFor($this->cohort),
            with: [
                'lead' => $this->lead,
                'cohort' => $this->cohort,
                'signupUrl' => $signupUrl,
                'promoCodeMonthly' => $this->lead->promo_code_monthly,
                'promoCodeYearly' => $this->lead->promo_code_yearly,
                'monthlyPrice' => (float) config('subscriptions.plans.monthly.price'),
                'yearlyPrice' => (float) config('subscriptions.plans.yearly.price'),
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

    private function viewFor(LeadCohort $cohort): string
    {
        return match ($cohort) {
            LeadCohort::Founder => 'mail.invitations.founder',
            LeadCohort::FounderReferrer => 'mail.invitations.founder-referrer',
            LeadCohort::EarlyBird => 'mail.invitations.early-bird',
            LeadCohort::Waitlist => 'mail.invitations.waitlist',
        };
    }

    private function subjectFor(LeadCohort $cohort): string
    {
        return match ($cohort) {
            LeadCohort::Founder => __("You're a Whisper Money founder — free forever 🎁"),
            LeadCohort::FounderReferrer => __('Your referral made you a Whisper Money founder — free forever 🎁'),
            LeadCohort::EarlyBird => __("You're in early — months on us at Whisper Money"),
            LeadCohort::Waitlist => __('Your Whisper Money invitation is here'),
        };
    }
}
