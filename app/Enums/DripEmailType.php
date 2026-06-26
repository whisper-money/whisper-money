<?php

namespace App\Enums;

enum DripEmailType: string
{
    case BankTransactionsSynced = 'bank_transactions_synced';
    case Welcome = 'welcome';
    case OnboardingReminder = 'onboarding_reminder';
    case PromoCode = 'promo_code';
    case ImportHelp = 'import_help';
    case Feedback = 'feedback';
    case SubscriptionCancelled = 'subscription_cancelled';
    case PaywallFollowUp = 'paywall_follow_up';
    case AiConsentFollowUp = 'ai_consent_follow_up';
    case Update = 'update';
}
