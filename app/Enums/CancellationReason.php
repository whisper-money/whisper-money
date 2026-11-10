<?php

namespace App\Enums;

/**
 * The answers to "Why are you cancelling?", asked by the in-app cancel flow.
 * Listed in the order the flow shows them, most expected first: the order
 * Stripe's portal survey saw them in, with the trial's precautionary cancels
 * high up because most cancellations happen during the trial.
 */
enum CancellationReason: string
{
    case NotUsingEnough = 'not_using_enough';

    case JustTrying = 'just_trying';

    case MissingFeature = 'missing_feature';

    case SwitchingApp = 'switching_app';

    case BankConnectionProblems = 'bank_connection_problems';

    case TooExpensive = 'too_expensive';

    case TooComplex = 'too_complex';

    case DontTrustBank = 'dont_trust_bank';

    case Other = 'other';

    /**
     * The closest of Stripe's own `cancellation_details.feedback` values, so
     * the reasons given here can be read next to the ones the portal's survey
     * collected before this flow existed.
     */
    public function stripeFeedback(): string
    {
        return match ($this) {
            self::NotUsingEnough => 'unused',
            self::MissingFeature, self::BankConnectionProblems => 'missing_features',
            self::SwitchingApp => 'switched_service',
            self::TooExpensive => 'too_expensive',
            self::TooComplex => 'too_complex',
            self::JustTrying, self::DontTrustBank, self::Other => 'other',
        };
    }
}
