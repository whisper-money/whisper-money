<?php

namespace App\Mail\Drip;

use App\Enums\DunningStep;

/**
 * The bank wants the customer to confirm the charge (3D Secure). Retrying
 * does not help with that, so without this email the payment fails silently
 * once Stripe gives up: Stripe's own confirmation email is switched off.
 *
 * The button opens Cashier's payment page, which walks them through the
 * bank's check and sends them back to the billing settings.
 */
class PaymentActionRequiredEmail extends DunningEmail
{
    protected function dripSubject(): string
    {
        return match (true) {
            $this->step === DunningStep::FinalNotice => __('Last notice: confirm your Whisper Money payment'),
            $this->step->isReminder() => __('Reminder: confirm your Whisper Money payment'),
            default => __('Your bank needs you to confirm your Whisper Money payment'),
        };
    }

    protected function template(): string
    {
        return 'mail.drip.payment-action-required';
    }

    /**
     * Without a payment intent to confirm (Stripe could not be asked for it),
     * the billing portal is the next best place: it can pay the open invoice
     * and runs the bank's check itself.
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            ...parent::contentData(),
            'confirmPaymentUrl' => $this->invoice->payment_intent_id !== null
                ? $this->invoice->confirmationUrl($this->utmParameters())
                : route('settings.billing.portal', $this->utmParameters()),
        ];
    }
}
