<?php

namespace App\Mail\Drip;

use App\Enums\DunningStep;
use App\Models\FailedInvoice;
use App\Models\User;
use App\Support\Money;

/**
 * What the failed-payment emails share: the invoice they chase, the step of
 * the sequence they are, and links tagged `utm_campaign=dunning` with the step
 * as `utm_content`, so PostHog can tell which email brought the reader back.
 *
 * Billing mail, so none of them is marketing: they always go out.
 */
abstract class DunningEmail extends DripMail
{
    public function __construct(
        User $user,
        public FailedInvoice $invoice,
        public DunningStep $step,
    ) {
        parent::__construct($user);
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function utmParameters(): array
    {
        return [
            ...parent::utmParameters(),
            'utm_campaign' => 'dunning',
            'utm_content' => $this->step->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'amount' => Money::formatIn($this->invoice->amount_due, $this->invoice->currency, $this->user->formatLocale()),
            'planName' => $this->planName(),
            'isFirstCharge' => $this->invoice->is_first_charge,
            'isReminder' => $this->step->isReminder(),
            'isFinalNotice' => $this->step === DunningStep::FinalNotice,
        ];
    }

    private function planName(): string
    {
        return match ($this->invoice->billing_interval) {
            'year' => __('Whisper Money annual plan'),
            'month' => __('Whisper Money monthly plan'),
            default => __('Whisper Money plan'),
        };
    }
}
