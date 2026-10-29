<?php

namespace App\Enums;

/**
 * Why a user may not approve an OAuth request. Mirrors the `/api/v1` gate
 * (EnsureApiAccess): the consent screen shows the reason instead of the
 * approve button, and an approval posted anyway is refused.
 */
enum OAuthApprovalRefusal: string
{
    case SharedAccount = 'shared_account';
    case PlanRequired = 'plan_required';

    public function title(): string
    {
        return match ($this) {
            self::SharedAccount => __('This account cannot connect apps to the API'),
            self::PlanRequired => __('The API is available on a paid plan'),
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::SharedAccount => __('Shared demo and press accounts cannot use the Whisper Money API.'),
            self::PlanRequired => __('Upgrade your plan to connect apps to your account, then come back and try again.'),
        };
    }
}
