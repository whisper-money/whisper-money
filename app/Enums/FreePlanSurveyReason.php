<?php

namespace App\Enums;

/**
 * The answers to "What's keeping you on free?", asked on the paywall when a
 * user chooses to stay on the free plan. Only `Other` carries free text.
 */
enum FreePlanSurveyReason: string
{
    case TooExpensive = 'too_expensive';

    case StillTrying = 'still_trying';

    case NoNeedForSyncOrAi = 'no_need_for_sync_or_ai';

    case DontTrustBankConnection = 'dont_trust_bank_connection';

    case UseTooLittle = 'use_too_little';

    case Other = 'other';
}
