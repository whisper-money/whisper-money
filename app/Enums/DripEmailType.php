<?php

namespace App\Enums;

enum DripEmailType: string
{
    case Welcome = 'welcome';
    case OnboardingReminder = 'onboarding_reminder';
    case PromoCode = 'promo_code';
    case ImportHelp = 'import_help';
    case Feedback = 'feedback';
    case SubscriptionCancelled = 'subscription_cancelled';
}
