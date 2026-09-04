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
    case BankOutage = 'bank_outage';
    case BankConnectFailed = 'bank_connect_failed';
    case BankNotice = 'bank_notice';
    case ConnectionExpiring = 'connection_expiring';
    case InactiveNoBank = 'inactive_no_bank';
    case TrialEnding = 'trial_ending';
    case MonthlySummary = 'monthly_summary';
    case MonthlySummaryReminder = 'monthly_summary_reminder';
    case AchievementsUnlocked = 'achievements_unlocked';

    /**
     * Emails whose only job is to nudge the user back into the app. They share a
     * cooldown so a manual-only user never gets two "update your data" messages
     * in the same week; operational mail (banks, billing, verification) is
     * deliberately absent and always goes out.
     *
     * @return list<self>
     */
    public static function nudges(): array
    {
        return [
            self::OnboardingReminder,
            self::ImportHelp,
            self::PaywallFollowUp,
            self::AiConsentFollowUp,
            self::InactiveNoBank,
            self::MonthlySummaryReminder,
        ];
    }
}
