<?php

namespace App\Enums;

use App\Jobs\Drip\SendDripEmailJob;

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
     * Emails that sell the app rather than run it: the onboarding sequence, the
     * nudges that explain a feature, the offers and the broadcast updates. They
     * are the ones "Product news and offers" switches off, and the list is the
     * single source of truth for that — both {@see SendDripEmailJob}
     * and the footer link read it.
     *
     * Operational mail is deliberately absent and always goes out: banks,
     * billing, verification, and the two categories that already carry a switch
     * of their own (the monthly summary and the achievements email). A reader
     * who wants no marketing has not asked to stop hearing that their bank
     * connection expired.
     *
     * @return list<self>
     */
    public static function marketing(): array
    {
        return [
            self::Welcome,
            self::OnboardingReminder,
            self::ImportHelp,
            self::Feedback,
            self::PromoCode,
            self::PaywallFollowUp,
            self::AiConsentFollowUp,
            self::Update,
        ];
    }

    public function isMarketing(): bool
    {
        return in_array($this, self::marketing(), true);
    }

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
