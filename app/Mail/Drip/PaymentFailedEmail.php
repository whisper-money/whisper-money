<?php

namespace App\Mail\Drip;

use App\Enums\DunningStep;

/**
 * The card was declined: Stripe keeps retrying and the plan keeps working
 * meanwhile, so the email says so without blame and asks for one thing, a
 * working payment method. Sent when the charge first fails and again as the
 * reminders of {@see DunningStep}.
 */
class PaymentFailedEmail extends DunningEmail
{
    protected function dripSubject(): string
    {
        return match (true) {
            $this->step === DunningStep::FinalNotice => __('Last notice: update your card to keep Whisper Money'),
            $this->step->isReminder() => __('Reminder: your Whisper Money payment is still pending'),
            $this->invoice->is_first_charge_after_trial => __('Your first Whisper Money payment didn\'t go through'),
            default => __('Your Whisper Money payment didn\'t go through'),
        };
    }

    protected function template(): string
    {
        return 'mail.drip.payment-failed';
    }

    /**
     * Straight to "update payment method" in Stripe's billing portal.
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            ...parent::contentData(),
            'updatePaymentUrl' => route('settings.billing.portal', [
                ...$this->utmParameters(),
                'flow' => 'payment_method_update',
            ]),
        ];
    }
}
